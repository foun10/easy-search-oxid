<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Index\MySql;

use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Index\MySql\TableSchema;
use foun10\EasySearch\Tests\Unit\Double\TestableIndexTables;
use foun10\EasySearch\Tests\Unit\Double\TestableMySqlIndexWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Writing the index into MySQL.
 *
 * Almost none of this class is about SQL syntax and almost all of it is about
 * order and safety: a rebuild fills a shadow table while search traffic keeps
 * hitting the live one, derives its facet counts from the rows it just wrote,
 * adds the fulltext indexes only once the data is in, and swaps all three
 * tables in a single RENAME. Get the order wrong and the shop serves a half
 * built index without anything failing - which is exactly the kind of bug a
 * test has to catch instead of a customer.
 *
 * The database is replaced by a transcript (see TestableMySqlIndexWriter), the
 * table names are the real ones (TestableIndexTables).
 */
class MySqlIndexWriterTest extends TestCase
{
    private TestableIndexTables $tables;

    private TestableMySqlIndexWriter $writer;

    protected function setUp(): void
    {
        $this->tables = new TestableIndexTables();
        $this->writer = new TestableMySqlIndexWriter($this->tables);
    }

    /**
     * @param array<int, array{attributeId: string, title: string, valueId: string, value: string}> $attributes
     * @param string[] $categoryPaths
     */
    private function document(
        string $id = 'doc-1',
        int $shopId = 1,
        int $langId = 0,
        string $articleId = 'a-1',
        string $parentId = '',
        array $attributes = [],
        ?string $insertDate = '2024-01-05',
        bool $visible = true,
        array $categoryPaths = []
    ): IndexDocument {
        return new IndexDocument(
            $id,
            $shopId,
            $langId,
            $articleId,
            $parentId,
            $parentId !== '' ? $parentId : $articleId,
            'Titel',
            '4711',
            '4006381333931',
            'MPN-9',
            'Marke',
            'm-1',
            $categoryPaths,
            $attributes,
            'search text',
            'boost text',
            19.99,
            3.0,
            7,
            $insertDate,
            $visible,
            []
        );
    }

    /**
     * @return array{attributeId: string, title: string, valueId: string, value: string}
     */
    private function attribute(string $attributeId, string $valueId, string $value): array
    {
        return [
            'attributeId' => $attributeId,
            'title' => 'Farbe',
            'valueId' => $valueId,
            'value' => $value,
        ];
    }

    /**
     * @return array<int, array{shopId: int, langId: int}>
     */
    private function scope(int $shopId = 1, int $langId = 0): array
    {
        return [['shopId' => $shopId, 'langId' => $langId]];
    }

    // ---------------------------------------------------------------
    // starting a run
    // ---------------------------------------------------------------

    public function testBeginCreatesTheThreeShadowTablesOfEveryShopInScope(): void
    {
        $this->writer->begin([['shopId' => 1, 'langId' => 0], ['shopId' => 2, 'langId' => 0]]);

        $this->assertSame(
            [
                'foun10easysearchindex_s1_tmp',
                'foun10easysearchindexattribute_s1_tmp',
                'foun10easysearchindexattributegroup_s1_tmp',
                'foun10easysearchindex_s2_tmp',
                'foun10easysearchindexattribute_s2_tmp',
                'foun10easysearchindexattributegroup_s2_tmp',
            ],
            $this->tables->shadowTableNames()
        );
    }

    /**
     * Bulk loading into a live fulltext index is roughly an order of magnitude
     * slower, so the index table is created without its keys and gets them
     * back just before the swap.
     */
    public function testTheIndexShadowIsCreatedWithoutItsFulltextIndexes(): void
    {
        $this->writer->begin($this->scope());

        $this->assertSame(
            [
                ['table' => 'foun10easysearchindex_s1_tmp', 'fulltext' => false],
                ['table' => 'foun10easysearchindexattribute_s1_tmp', 'fulltext' => true],
                ['table' => 'foun10easysearchindexattributegroup_s1_tmp', 'fulltext' => true],
            ],
            $this->tables->shadowsCreated
        );
    }

    /**
     * No scope means the whole installation.
     */
    public function testWithoutAScopeEveryShopIsPrepared(): void
    {
        $this->writer->allShopIds = [1, 2, 5];
        $this->writer->begin();

        $this->assertSame(
            ['foun10easysearchindex_s1_tmp', 'foun10easysearchindex_s2_tmp', 'foun10easysearchindex_s5_tmp'],
            array_values(array_filter(
                $this->tables->shadowTableNames(),
                static fn (string $name): bool => str_starts_with($name, 'foun10easysearchindex_s')
            ))
        );
    }

    /**
     * Several languages of one shop are one set of tables, because language is
     * a column and not a table.
     */
    public function testTwoLanguagesOfOneShopPrepareOneSetOfTables(): void
    {
        $this->writer->begin([['shopId' => 1, 'langId' => 0], ['shopId' => 1, 'langId' => 1]]);

        $this->assertCount(3, $this->tables->shadowsCreated);
    }

    public function testAStartedRunCannotBeStartedAgain(): void
    {
        $this->writer->begin($this->scope());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already started');

        $this->writer->begin($this->scope());
    }

    public function testWritingWithoutStartingIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not started');

        $this->writer->write([$this->document()]);
    }

    public function testCommittingWithoutStartingIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not started');

        $this->writer->commit();
    }

    /**
     * A browser driven rebuild runs across many requests: the shadow tables
     * an earlier one created are still there, and this object only has to
     * remember what to swap.
     */
    public function testResumingAdoptsTheTablesAnEarlierRequestCreated(): void
    {
        $this->writer->resume($this->scope());
        $this->writer->write([$this->document()]);

        $this->assertSame([], $this->tables->shadowsCreated, 'nothing is recreated');
        $this->assertStringContainsString('foun10easysearchindex_s1_tmp', $this->writer->statementContaining('INSERT INTO'));
    }

    /**
     * The step exists because the browser driven rebuild has a phase for it.
     * There is nothing left to clear, so it answers zero and the caller's loop
     * ends after one tick - but it does make sure a shadow table left behind
     * by a dead run is replaced rather than filled twice.
     */
    public function testClearingAScopeReplacesTheShadowTablesAndEndsTheLoop(): void
    {
        $this->assertSame(0, $this->writer->clearScopeBatch(1, 0, 500));
        $this->assertCount(3, $this->tables->shadowsCreated);
    }

    // ---------------------------------------------------------------
    // writing documents
    // ---------------------------------------------------------------

    public function testAnEmptyBatchWritesNothing(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([]);

        $this->assertSame([], $this->writer->statements);
    }

    public function testEachShopIsWrittenToItsOwnTable(): void
    {
        $this->writer->begin([['shopId' => 1, 'langId' => 0], ['shopId' => 2, 'langId' => 0]]);
        $this->writer->write([
            $this->document(id: 'doc-1', shopId: 1),
            $this->document(id: 'doc-2', shopId: 2),
        ]);

        $inserts = array_values(array_filter(
            $this->writer->executedSql(),
            static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO')
        ));

        $this->assertStringContainsString('foun10easysearchindex_s1_tmp', $inserts[0]);
        $this->assertStringContainsString("'doc-1'", $inserts[0]);
        $this->assertStringNotContainsString("'doc-2'", $inserts[0]);
        $this->assertStringContainsString('foun10easysearchindex_s2_tmp', $inserts[1]);
        $this->assertStringContainsString("'doc-2'", $inserts[1]);
    }

    /**
     * A shop that only shows up in the documents still gets its tables - the
     * scope of a run is not necessarily the scope of a batch.
     */
    public function testAShopThatOnlyAppearsInTheDocumentsIsPreparedToo(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document(id: 'doc-2', shopId: 2)]);

        $this->assertContains('foun10easysearchindex_s2_tmp', $this->tables->shadowTableNames());
    }

    public function testOneBatchIsOneStatementPerTable(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([
            $this->document(id: 'doc-1', attributes: [$this->attribute('at-color', 'v-red', 'Rot')]),
            $this->document(id: 'doc-2', attributes: [$this->attribute('at-color', 'v-blue', 'Blau')]),
        ]);

        $this->assertSame(['insert-documents', 'insert-attributes'], $this->writer->steps());
        $this->assertStringStartsWith(
            'INSERT INTO foun10easysearchindex_s1_tmp (',
            $this->writer->statementContaining('INSERT INTO')
        );
        $this->assertStringContainsString("'doc-1'", $this->writer->statementContaining('INSERT INTO'));
        $this->assertStringContainsString("'doc-2'", $this->writer->statementContaining('INSERT INTO'));
        $this->assertStringStartsWith(
            'INSERT IGNORE INTO foun10easysearchindexattribute_s1_tmp (',
            $this->writer->statementContaining('INSERT IGNORE')
        );
    }

    public function testAnArticleWithoutAnInsertDateGetsNullRatherThanAnEmptyDate(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document(insertDate: null)]);

        $sql = $this->writer->statementContaining('INSERT INTO');

        $this->assertStringContainsString(', NULL)', $sql);
        $this->assertStringNotContainsString(", '')", $sql, 'an empty string is not a date either');
    }

    public function testTheInsertDateIsQuotedWhenThereIsOne(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document(insertDate: '2024-01-05')]);

        $this->assertStringContainsString("'2024-01-05'", $this->writer->statementContaining('INSERT INTO'));
    }

    /**
     * The visibility gate every search query applies, written as a column.
     */
    public function testVisibilityIsWrittenAsOneOrZero(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([
            $this->document(id: 'doc-1', visible: true),
            $this->document(id: 'doc-2', visible: false),
        ]);

        $sql = $this->writer->statementContaining('INSERT INTO');

        $this->assertStringContainsString("'doc-1', 1, 0, 'a-1', '', 'a-1', 1,", $sql);
        $this->assertStringContainsString("'doc-2', 1, 0, 'a-1', '', 'a-1', 0,", $sql);
    }

    public function testCategoryPathsTravelInOneColumn(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document(categoryPaths: ['Damen > Waesche', 'Sale'])]);

        $this->assertStringContainsString(
            "'Damen > Waesche %% Sale'",
            $this->writer->statementContaining('INSERT INTO')
        );
    }

    /**
     * One article can carry the same value through its own and its parent
     * assignment, which would collide on the primary key - and a collision in
     * the middle of a rebuild would fail the whole batch.
     */
    public function testAttributeRowsAreInsertedIgnoringCollisions(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document(attributes: [$this->attribute('at-color', 'v-red', 'Rot')])]);

        $sql = $this->writer->statementContaining('INSERT IGNORE');

        $this->assertStringContainsString('foun10easysearchindexattribute_s1_tmp', $sql);
        $this->assertStringContainsString("'" . md5('doc-1at-colorv-red') . "'", $sql);
        $this->assertStringContainsString("'at-color', 'v-red', 'Rot'", $sql);
    }

    public function testAnArticleWithoutAttributesRunsNoAttributeStatement(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document()]);

        $this->assertSame(['insert-documents'], $this->writer->steps());
    }

    /**
     * The sort position is the order within one article, so it restarts for
     * the next one.
     */
    public function testAttributePositionsRestartWithEachArticle(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([
            $this->document(id: 'doc-1', attributes: [
                $this->attribute('at-color', 'v-red', 'Rot'),
                $this->attribute('at-size', 'v-40', '40'),
            ]),
            $this->document(id: 'doc-2', attributes: [
                $this->attribute('at-color', 'v-blue', 'Blau'),
            ]),
        ]);

        $sql = $this->writer->statementContaining('INSERT IGNORE');

        $this->assertStringContainsString("'Rot', 0)", $sql);
        $this->assertStringContainsString("'40', 1)", $sql);
        $this->assertStringContainsString("'Blau', 0)", $sql);
    }

    // ---------------------------------------------------------------
    // going live
    // ---------------------------------------------------------------

    /**
     * The order is the whole point: the facet counts are derived from the rows
     * this run wrote, the fulltext indexes go on after the data, and only then
     * does anything become visible to a customer.
     */
    public function testCommitDerivesThenIndexesThenSwaps(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->write([$this->document()]);
        $this->writer->commit();

        $this->assertSame(
            [
                'insert-documents',
                'drop-label-choice',
                'create-label-choice',
                'fill-label-choice',
                'fill-attribute-groups',
                'add-fulltext',
                'add-fulltext',
                'swap',
            ],
            $this->writer->steps()
        );
    }

    /**
     * MariaDB accepts both keys in one ALTER; MySQL 5.7 answers "InnoDB
     * presently supports one FULLTEXT index creation at a time" and fails the
     * rebuild at its very last step, after the catalogue has been read.
     */
    public function testTheTwoFulltextIndexesAreAddedOneStatementEach(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->commit();

        $alters = array_values(array_filter(
            $this->writer->executedSql(),
            static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE')
        ));

        $this->assertSame(
            [
                'ALTER TABLE foun10easysearchindex_s1_tmp'
                . ' ADD FULLTEXT KEY FOUN10FT_SEARCHTEXT (FOUN10EASYSEARCHTEXT)',
                'ALTER TABLE foun10easysearchindex_s1_tmp'
                . ' ADD FULLTEXT KEY FOUN10FT_BOOST (FOUN10EASYSEARCHTEXTBOOST)',
            ],
            $alters,
            'on the shadow table, before it goes live'
        );
    }

    /**
     * The label choice is derived per run and must not survive the run that
     * made it - so it is dropped first and created fresh.
     */
    public function testTheLabelChoiceIsATemporaryTableOfItsOwn(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->commit();

        $statements = $this->writer->executedSql();

        $this->assertSame('DROP TEMPORARY TABLE IF EXISTS foun10easysearchlabelchoice_tmp', $statements[0]);
        $this->assertStringStartsWith(
            'CREATE TEMPORARY TABLE foun10easysearchlabelchoice_tmp (',
            $statements[1]
        );
    }

    /**
     * Both derived statements read the rows this run wrote - the shadow
     * tables - and count only visible variants, which is the same gate every
     * search query applies. Reading the live tables instead would describe the
     * index that is about to be replaced.
     */
    public function testTheFacetCountsAreDerivedFromWhatThisRunWrote(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->commit();

        $labelChoice = $this->writer->statementContaining('INSERT INTO foun10easysearchlabelchoice_tmp');

        $this->assertStringStartsWith('INSERT INTO foun10easysearchlabelchoice_tmp', $labelChoice);
        $this->assertStringContainsString('FROM foun10easysearchindexattribute_s1_tmp AS a', $labelChoice);
        $this->assertStringContainsString('INNER JOIN foun10easysearchindex_s1_tmp AS i', $labelChoice);
        $this->assertStringContainsString('ON i.OXID = a.FOUN10INDEXID AND i.FOUN10VISIBLE = 1', $labelChoice);

        $groups = $this->writer->statementContaining('indexattributegroup_s1_tmp');

        $this->assertStringStartsWith('INSERT INTO foun10easysearchindexattributegroup_s1_tmp (', $groups);
        $this->assertStringContainsString('FROM foun10easysearchindexattribute_s1_tmp AS a', $groups);
        $this->assertStringContainsString('INNER JOIN foun10easysearchindex_s1_tmp AS i', $groups);
        $this->assertStringContainsString('ON i.OXID = a.FOUN10INDEXID AND i.FOUN10VISIBLE = 1', $groups);
        $this->assertStringContainsString('LEFT JOIN foun10easysearchlabelchoice_tmp AS c', $groups);
    }

    /**
     * All three tables in one RENAME, so the index can never be live out of
     * sync with the facet tables that describe it.
     */
    public function testTheThreeTablesGoLiveInOneStatement(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->commit();

        $this->assertSame(
            'RENAME TABLE '
            . 'foun10easysearchindex_s1 TO foun10easysearchindex_s1_old, '
            . 'foun10easysearchindex_s1_tmp TO foun10easysearchindex_s1, '
            . 'foun10easysearchindexattribute_s1 TO foun10easysearchindexattribute_s1_old, '
            . 'foun10easysearchindexattribute_s1_tmp TO foun10easysearchindexattribute_s1, '
            . 'foun10easysearchindexattributegroup_s1 TO foun10easysearchindexattributegroup_s1_old, '
            . 'foun10easysearchindexattributegroup_s1_tmp TO foun10easysearchindexattributegroup_s1',
            $this->writer->statementContaining('RENAME TABLE')
        );
    }

    /**
     * RENAME needs both sides, and a shop indexed for the first time has only
     * the shadow - so the live tables are created empty first.
     */
    public function testTheLiveTablesAreCreatedIfTheShopWasNeverIndexed(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->commit();

        $this->assertSame(
            [
                'foun10easysearchindex_s1',
                'foun10easysearchindexattribute_s1',
                'foun10easysearchindexattributegroup_s1',
            ],
            $this->tables->created
        );
    }

    public function testTheReplacedTablesAreDroppedBeforeAndAfterTheSwap(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->commit();

        $this->assertSame(
            [
                'foun10easysearchindex_s1_old',
                'foun10easysearchindexattribute_s1_old',
                'foun10easysearchindexattributegroup_s1_old',
                'foun10easysearchindex_s1_old',
                'foun10easysearchindexattribute_s1_old',
                'foun10easysearchindexattributegroup_s1_old',
            ],
            $this->tables->dropped,
            'the leftovers of a failed run first, then what this run replaced'
        );
    }

    /**
     * After a swap a name that existed a moment ago points at different data.
     */
    public function testTheTableCacheIsForgottenAfterASwap(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->commit();

        $this->assertSame(2, $this->tables->forgets, 'once after the swap, once when the run resets');
    }

    /**
     * A rebuild of one shop leaves the others exactly as they were - which is
     * what the table per shop bought in the first place.
     */
    public function testOnlyTheShopsThatWereWrittenToAreSwapped(): void
    {
        $this->writer->allShopIds = [1, 2];
        $this->writer->begin($this->scope(2));
        $this->writer->write([$this->document(shopId: 2)]);
        $this->writer->commit();

        $rename = $this->writer->statementContaining('RENAME TABLE');

        $this->assertStringContainsString('foun10easysearchindex_s2_tmp', $rename);
        $this->assertStringNotContainsString('_s1', $rename);
    }

    public function testTheRunIsOverAfterACommit(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->commit();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not started');

        $this->writer->write([$this->document()]);
    }

    /**
     * A failed run leaves nothing behind but the live index it never touched.
     */
    public function testRollbackDropsEveryShadowTableOfTheRun(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->rollback();

        $this->assertSame(
            array_map(
                fn (string $table): string => $this->tables->shadow($this->tables->name($table, 1)),
                TableSchema::TABLES
            ),
            $this->tables->dropped
        );
        $this->assertSame([], $this->writer->executedSql(), 'nothing was published');
    }

    public function testTheRunIsOverAfterARollback(): void
    {
        $this->writer->begin($this->scope());
        $this->writer->rollback();

        $this->expectException(RuntimeException::class);

        $this->writer->write([$this->document()]);
    }

    // ---------------------------------------------------------------
    // incremental delete
    // ---------------------------------------------------------------

    public function testDeletingFromAShopThatWasNeverIndexedDoesNothing(): void
    {
        $this->writer->delete('a-1', 1, 0);

        $this->assertSame([], $this->writer->executedSql());
        $this->assertSame([], $this->writer->reads);
    }

    public function testDeletingRemovesTheAttributeRowsBeforeTheIndexRows(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->columnValues = ['doc-1', 'doc-2'];

        $this->writer->delete('a-1', 1, 0);

        $statements = $this->writer->executedSql();

        $this->assertCount(2, $statements);
        $this->assertSame(
            "DELETE FROM foun10easysearchindexattribute_s1 WHERE FOUN10INDEXID IN ('doc-1', 'doc-2')",
            $statements[0]
        );
        $this->assertStringStartsWith('DELETE FROM foun10easysearchindex_s1', $statements[1]);
        $this->assertStringContainsString(
            'WHERE FOUN10ARTICLEID = :articleId AND FOUN10LANGID = :langId',
            $statements[1],
            'a DELETE without its condition would empty the index'
        );
        $this->assertStringContainsString(
            'SELECT OXID FROM foun10easysearchindex_s1',
            $this->writer->reads[0]['sql']
        );
        $this->assertStringContainsString(
            'WHERE FOUN10ARTICLEID = :articleId AND FOUN10LANGID = :langId',
            $this->writer->reads[0]['sql']
        );
    }

    /**
     * Nothing indexed for that article means no orphans to clean up either.
     */
    public function testDeletingAnArticleThatIsNotIndexedSkipsTheAttributeStatement(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->columnValues = [];

        $this->writer->delete('a-1', 1, 0);

        $this->assertCount(1, $this->writer->executedSql());
        $this->assertStringStartsWith('DELETE FROM foun10easysearchindex_s1', $this->writer->executedSql()[0]);
    }

    /**
     * The article ID comes from an ERP import, so it is bound rather than
     * pasted into the statement.
     */
    public function testTheArticleAndLanguageAreBoundParameters(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->columnValues = [];

        $this->writer->delete("a-1' OR 1=1 --", 1, 2);

        $this->assertSame([':articleId' => "a-1' OR 1=1 --", ':langId' => 2], $this->writer->reads[0]['parameters']);
        $this->assertSame(
            [':articleId' => "a-1' OR 1=1 --", ':langId' => 2],
            $this->writer->statements[0]['parameters']
        );
    }

    // ---------------------------------------------------------------
    // deriving the category assignments
    // ---------------------------------------------------------------

    public function testNothingIndexedMeansNothingToDerive(): void
    {
        $result = $this->writer->rebuildCategories(1, 0);

        $this->assertTrue($result->isPublished());
        $this->assertSame(0, $result->getWritten());
        $this->assertSame(0, $result->getPrevious(), 'nothing was live either');
        $this->assertSame([], $this->writer->executedSql());
    }

    public function testAPlausibleSourceIsPublishedInATransaction(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->counts = [100, 80, 100];

        $result = $this->writer->rebuildCategories(1, 0);

        $this->assertTrue($result->isPublished());
        $this->assertSame(100, $result->getWritten());
        $this->assertSame(80, $result->getPrevious());
        $this->assertSame(['start', 'commit'], $this->writer->transaction);
        $this->assertSame(['delete', 'insert-categories'], $this->writer->steps());
    }

    /**
     * Rebuilding while the ERP import has oxobject2category truncated would
     * blank every category page in the shop. The source has to still hold half
     * of what is live.
     */
    public function testASourceThatLostMostOfItsRowsIsRefused(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->counts = [30, 100];

        $result = $this->writer->rebuildCategories(1, 0);

        $this->assertFalse($result->isPublished());
        $this->assertSame(30, $result->getAvailable());
        $this->assertSame(100, $result->getPrevious());
        $this->assertSame([], $this->writer->transaction, 'nothing was even opened');
        $this->assertSame([], $this->writer->executedSql());
    }

    /**
     * @dataProvider plausibilityProvider
     */
    public function testWhereTheLineIsDrawn(int $available, int $previous, bool $expected): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->counts = [$available, $previous, $available];

        $this->assertSame($expected, $this->writer->rebuildCategories(1, 0)->isPublished());
    }

    /**
     * @return array<string, array{int, int, bool}>
     */
    public function plausibilityProvider(): array
    {
        return [
            'exactly half is still plausible' => [50, 100, true],
            'one below half is not' => [49, 100, false],
            'more than before' => [120, 100, true],
            // A first run has nothing to protect. Refusing it would mean the
            // index could never be built in the first place.
            'the first run publishes an empty source' => [0, 0, true],
            'the first run publishes anything' => [5, 0, true],
            // An odd number rounds up, so half of 101 is 51.
            'half of an odd count rounds up' => [50, 101, false],
        ];
    }

    public function testForcePublishesEvenAnImplausibleSource(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->counts = [1, 100, 1];

        $result = $this->writer->rebuildCategories(1, 0, true);

        $this->assertTrue($result->isPublished());
        $this->assertSame(1, $result->getWritten());
    }

    /**
     * The language scopes share the table, so a reader has to see one state or
     * the other - never the gap between the delete and the insert.
     */
    public function testAFailedInsertRollsBackAndSaysSo(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->counts = [100, 80, 100];
        $this->writer->failWhenSqlContains = 'INSERT INTO';

        try {
            $this->writer->rebuildCategories(1, 0);
            $this->fail('the exception has to reach the caller');
        } catch (RuntimeException $exception) {
            $this->assertSame('the database refused the statement', $exception->getMessage());
        }

        $this->assertSame(['start', 'rollback'], $this->writer->transaction);
    }

    public function testOnlyTheLanguageBeingRebuiltIsReplaced(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->counts = [100, 80, 100];

        $this->writer->rebuildCategories(1, 2);

        $this->assertStringContainsString('FOUN10LANGID = 2', $this->writer->statementContaining('DELETE'));
        $this->assertStringNotContainsString('FOUN10LANGID = 0', $this->writer->statementContaining('DELETE'));
    }

    /**
     * Restricted to groups that are actually indexed, so the table cannot grow
     * assignments for products the search would never return - and DISTINCT on
     * the group first, or every assignment would be multiplied by the number
     * of variants before being deduplicated again.
     */
    public function testTheSourceIsTheShopsAssignmentsForIndexedGroupsOnly(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s1'];
        $this->writer->counts = [100, 80, 100];

        $this->writer->rebuildCategories(1, 2);

        $sql = $this->writer->statementContaining('INSERT INTO');

        $this->assertStringStartsWith('INSERT INTO foun10easysearchindexcategory_s1 (', $sql);
        $this->assertStringContainsString('SELECT DISTINCT FOUN10GROUPID', $sql);
        $this->assertStringContainsString('FROM foun10easysearchindex_s1', $sql);
        $this->assertStringContainsString('WHERE FOUN10LANGID = 2', $sql);
        $this->assertStringContainsString('JOIN oxv_oxobject2category_1_2 AS o2c', $sql);
        $this->assertStringContainsString('ON o2c.OXOBJECTID = grouped.FOUN10GROUPID', $sql);
        $this->assertStringContainsString(') AS assignments', $sql);
    }

    public function testTheDerivedRowsAreKeyedByTheirScope(): void
    {
        $this->tables->existing = ['foun10easysearchindex_s3'];
        $this->writer->counts = [100, 80, 100];

        $this->writer->rebuildCategories(3, 2);

        $sql = $this->writer->statementContaining('INSERT INTO');

        $this->assertStringContainsString('MD5(CONCAT_WS("_", 3, 2, groupId, catId))', $sql);
        $this->assertStringContainsString('3, 2, groupId, catId', $sql, 'and written into the columns too');
    }
}
