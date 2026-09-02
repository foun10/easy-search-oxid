<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Integration;

use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Engine\Query\FacetFilter;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Index\IndexWriterInterface;
use foun10\EasySearch\Index\MySql\IndexTables;
use foun10\EasySearch\Index\MySql\TableSchema;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use PHPUnit\Framework\TestCase;

/**
 * The write path, against a shop of its own.
 *
 * The unit tests prove the writer issues the right statements in the right
 * order - fill a shadow table, derive the facet counts from what was just
 * written, add the fulltext keys after the data, swap all three tables in one
 * RENAME. They cannot prove MySQL accepts any of it, and every bug this module
 * has had in that area was of exactly that kind.
 *
 * The isolation is what makes this safe to run against a live shop: index
 * tables are **per shop**, so writing as shop 990 creates
 * foun10easysearchindex_s990 and friends, touches nothing the running shop
 * reads, and can be dropped whole afterwards. That is also why this is the one
 * place the full rebuild path - the one that renames tables - can be exercised
 * at all.
 */
class IndexWriterTest extends TestCase
{
    /** A shop no installation uses, so its index tables are ours alone */
    private const SCRATCH_SHOP_ID = 990;

    private const LANG_ID = 0;

    private IndexWriterInterface $writer;

    private SearchEngineInterface $engine;

    private IndexTables $tables;

    protected function setUp(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();

        $this->writer = $container->get(IndexWriterInterface::class);
        $this->engine = $container->get(SearchEngineInterface::class);
        $this->tables = $container->get(IndexTables::class);

        $this->dropScratchTables();
    }

    protected function tearDown(): void
    {
        $this->dropScratchTables();
    }

    /**
     * Every table this shop could have, including the shadow and retired names
     * a failed run would leave behind.
     */
    private function dropScratchTables(): void
    {
        $database = DatabaseProvider::getDb();

        foreach ([TableSchema::INDEX, TableSchema::ATTRIBUTE, TableSchema::ATTRIBUTE_GROUP, TableSchema::CATEGORY] as $table) {
            $name = $this->tables->name($table, self::SCRATCH_SHOP_ID);

            foreach ([$name, $this->tables->shadow($name), $this->tables->retired($name)] as $candidate) {
                $database->execute('DROP TABLE IF EXISTS ' . $candidate);
            }
        }

        foreach ([\foun10\EasySearch\Index\DictionaryBuilder::TABLE, 'foun10easysearchattribute'] as $shared) {
            $database->execute(
                'DELETE FROM ' . $shared . ' WHERE OXSHOPID = :shopId',
                [':shopId' => self::SCRATCH_SHOP_ID]
            );
        }

        // The tables it remembers creating are gone, and a cached "this exists"
        // would make the next rebuild skip creating them again.
        $this->tables->forget();
    }

    /**
     * @param array<int, array{attributeId: string, title: string, valueId: string, value: string, hex: string|null}> $attributes
     */
    private function document(
        string $articleId,
        string $title,
        string $searchText,
        array $attributes = [],
        bool $visible = true,
        string $groupId = ''
    ): IndexDocument {
        return new IndexDocument(
            md5($articleId . '_' . self::SCRATCH_SHOP_ID . '_' . self::LANG_ID),
            self::SCRATCH_SHOP_ID,
            self::LANG_ID,
            $articleId,
            '',
            $groupId !== '' ? $groupId : $articleId,
            $title,
            'ART-' . $articleId,
            '',
            '',
            'Testmarke',
            'man-1',
            ['Damen > Jacken'],
            $attributes,
            $searchText,
            $title,
            19.99,
            5.0,
            0,
            null,
            $visible,
            ['cat-1']
        );
    }

    /**
     * @param IndexDocument[] $documents
     */
    private function rebuild(array $documents): void
    {
        $this->writer->begin([['shopId' => self::SCRATCH_SHOP_ID, 'langId' => self::LANG_ID]]);
        $this->writer->write($documents);
        $this->writer->commit();
    }

    private function search(string $term, array $filters = []): \foun10\EasySearch\Engine\Result\SearchResult
    {
        return $this->engine->search(
            new SearchQuery($term, self::SCRATCH_SHOP_ID, self::LANG_ID, $filters)
        );
    }

    // ---------------------------------------------------------------
    // a rebuild from nothing
    // ---------------------------------------------------------------

    /**
     * The whole path, end to end: a shop with no tables at all gets them, the
     * documents land, and the engine can answer from them.
     */
    public function testAShopWithNoIndexAtAllCanBeBuiltAndSearched(): void
    {
        $this->assertFalse(
            $this->engine->isAvailable(self::SCRATCH_SHOP_ID, self::LANG_ID),
            'The scratch shop was supposed to start with no index.'
        );

        $this->rebuild([
            $this->document('a-1', 'Winterjacke', 'winterjacke daunen warm'),
            $this->document('a-2', 'Sommerkleid', 'sommerkleid leicht baumwolle'),
        ]);

        $this->assertTrue($this->engine->isAvailable(self::SCRATCH_SHOP_ID, self::LANG_ID));
        $this->assertSame(1, $this->search('winterjacke')->getTotalCount());
    }

    public function testTheDocumentsThatWereWrittenAreTheOnesFound(): void
    {
        $this->rebuild([
            $this->document('a-1', 'Winterjacke', 'winterjacke daunen'),
            $this->document('a-2', 'Sommerkleid', 'sommerkleid leicht'),
        ]);

        $this->assertSame(['a-1'], $this->search('daunen')->getProductIds());
        $this->assertSame(['a-2'], $this->search('leicht')->getProductIds());
    }

    /**
     * The fulltext key is added after the data rather than before, because
     * building it once over a filled table is far cheaper than maintaining it
     * per row - so a search only works if that step actually ran.
     */
    public function testTheFulltextIndexExistsOnTheLiveTable(): void
    {
        $this->rebuild([$this->document('a-1', 'Winterjacke', 'winterjacke daunen')]);

        $keys = DatabaseHelper::fetchAll(
            'SHOW INDEX FROM ' . $this->tables->index(self::SCRATCH_SHOP_ID)
        );

        $types = array_column($keys, 'Index_type');

        $this->assertContains('FULLTEXT', $types, 'Without the fulltext key every search is a table scan.');
    }

    /**
     * A rebuild replaces the scope rather than adding to it, or a deleted
     * article would stay findable for ever.
     */
    public function testASecondRebuildReplacesTheFirst(): void
    {
        $this->rebuild([$this->document('a-1', 'Winterjacke', 'winterjacke daunen')]);
        $this->rebuild([$this->document('a-2', 'Sommerkleid', 'sommerkleid leicht')]);

        $this->assertSame(0, $this->search('daunen')->getTotalCount());
        $this->assertSame(1, $this->search('leicht')->getTotalCount());
    }

    /**
     * The swap is a RENAME of three tables in one statement, so nothing must be
     * left behind under a shadow or retired name - those are what the doctor
     * command reports as a rebuild that died halfway.
     */
    public function testTheSwapLeavesNoShadowOrRetiredTablesBehind(): void
    {
        $this->rebuild([$this->document('a-1', 'Winterjacke', 'winterjacke daunen')]);

        $live = $this->tables->index(self::SCRATCH_SHOP_ID);

        $this->assertSame([], $this->tablesNamed($this->tables->shadow($live)));
        $this->assertSame([], $this->tablesNamed($this->tables->retired($live)));
    }

    /**
     * A product the shop would not show must not be findable, and visibility is
     * decided while writing rather than while searching - so this is a property
     * of the row, not of the query.
     */
    public function testAnInvisibleProductIsWrittenButNotFound(): void
    {
        $this->rebuild([
            $this->document('a-1', 'Winterjacke', 'winterjacke daunen', [], false),
            $this->document('a-2', 'Sommerjacke', 'sommerjacke daunen', [], true),
        ]);

        $this->assertSame(['a-2'], $this->search('daunen')->getProductIds());
    }

    // ---------------------------------------------------------------
    // facets, derived from what was just written
    // ---------------------------------------------------------------

    /**
     * An attribute is only a facet because a merchant said so - indexing its
     * values is not the same as offering them as a filter. Worth its own test:
     * it is the difference between the two tables the rebuild writes.
     */
    public function testAnAttributeNobodyConfiguredIsIndexedButNotOffered(): void
    {
        $this->rebuild([
            $this->document('a-1', 'Jacke rot', 'jacke daunen', [$this->colour('red', 'Rot')]),
        ]);

        $this->assertSame(1, $this->search('daunen')->getTotalCount());
        $this->assertSame([], $this->search('daunen')->getFacets());
    }

    /**
     * Facet counts are derived in SQL from the rows the same rebuild wrote,
     * which is the step that cannot be checked without a database.
     */
    public function testFacetsAreDerivedFromTheWrittenAttributes(): void
    {
        $this->configureColourAsFacet();

        $this->rebuild([
            $this->document('a-1', 'Jacke rot', 'jacke daunen', [$this->colour('red', 'Rot')]),
            $this->document('a-2', 'Jacke blau', 'jacke daunen', [$this->colour('blue', 'Blau')]),
            $this->document('a-3', 'Jacke rot', 'jacke daunen', [$this->colour('red', 'Rot')]),
        ]);

        $facets = $this->search('daunen')->getFacets();

        $this->assertCount(1, $facets);
        $this->assertSame('attr-colour', $facets[0]->getAttributeId());
        $this->assertSame(
            ['red', 'blue'],
            array_map(
                static fn ($value): string => $value->getValueId(),
                $facets[0]->getValues()
            )
        );
    }

    public function testSelectingAFacetValueNarrowsWhatIsFound(): void
    {
        $this->configureColourAsFacet();

        $this->rebuild([
            $this->document('a-1', 'Jacke rot', 'jacke daunen', [$this->colour('red', 'Rot')]),
            $this->document('a-2', 'Jacke blau', 'jacke daunen', [$this->colour('blue', 'Blau')]),
        ]);

        $filtered = $this->search('daunen', [new FacetFilter('attr-colour', ['red'])]);

        $this->assertSame(['a-1'], $filtered->getProductIds());
    }

    /**
     * The counts are computed per product rather than per variant row, which is
     * the whole reason for the second, product-level table.
     */
    public function testTwoVariantsOfOneProductCountAsOneProduct(): void
    {
        $this->configureColourAsFacet();

        // Two variants of one product in red, and a second product in blue so
        // the facet has two values and is offered at all - a group with one
        // choice narrows nothing and is dropped on purpose.
        $this->rebuild([
            $this->document('a-1', 'Jacke M', 'jacke daunen', [$this->colour('red', 'Rot')], true, 'group-1'),
            $this->document('a-2', 'Jacke L', 'jacke daunen', [$this->colour('red', 'Rot')], true, 'group-1'),
            $this->document('a-3', 'Kleid', 'kleid daunen', [$this->colour('blue', 'Blau')], true, 'group-2'),
        ]);

        $values = [];

        foreach ($this->search('daunen')->getFacets()[0]->getValues() as $value) {
            $values[$value->getValueId()] = $value->getCount();
        }

        $this->assertSame(
            1,
            $values['red'],
            'Two variants of one product were counted as two products - the facet counts '
            . 'variant rows instead of the product level table.'
        );
        $this->assertSame(1, $values['blue']);
    }

    /**
     * A facet offering one choice cannot narrow anything the customer is not
     * already looking at, so it is a control that costs a click to discover it
     * does nothing. Dropped while the result is assembled rather than hidden in
     * the template, so the panel, the badge and the count all agree.
     */
    public function testAFacetWithOnlyOneChoiceIsNotOffered(): void
    {
        $this->configureColourAsFacet();

        $this->rebuild([
            $this->document('a-1', 'Jacke M', 'jacke daunen', [$this->colour('red', 'Rot')], true, 'group-1'),
            $this->document('a-2', 'Jacke L', 'jacke daunen', [$this->colour('red', 'Rot')], true, 'group-2'),
        ]);

        $this->assertSame(2, $this->search('daunen')->getTotalCount());
        $this->assertSame([], $this->search('daunen')->getFacets());
    }

    // ---------------------------------------------------------------
    // a rebuild that fails
    // ---------------------------------------------------------------

    /**
     * A failed run must leave the live index exactly as it was. The shadow
     * design exists for this: nothing the customer searches is touched until
     * the swap, so a rollback is simply not swapping.
     */
    public function testARolledBackRebuildLeavesTheLiveIndexAlone(): void
    {
        $this->rebuild([$this->document('a-1', 'Winterjacke', 'winterjacke daunen')]);

        $this->writer->begin([['shopId' => self::SCRATCH_SHOP_ID, 'langId' => self::LANG_ID]]);
        $this->writer->write([$this->document('a-2', 'Sommerkleid', 'sommerkleid leicht')]);
        $this->writer->rollback();

        $this->assertSame(
            ['a-1'],
            $this->search('daunen')->getProductIds(),
            'A rolled back rebuild replaced the live index anyway.'
        );
        $this->assertSame(0, $this->search('leicht')->getTotalCount());
    }

    /**
     * Marks the colour attribute as a facet for the scratch shop.
     *
     * The rebuild writes every attribute into the index, but only a configured
     * one is offered as a filter - so a facet test has to say so first, exactly
     * as a merchant would on the Attributes screen.
     */
    private function configureColourAsFacet(): void
    {
        ContainerFactory::getInstance()->getContainer()
            ->get(\foun10\EasySearch\Core\AttributeConfiguration::class)
            ->save(self::SCRATCH_SHOP_ID, [
                ['attributeId' => 'attr-colour', 'facet' => true, 'searchable' => true],
            ]);
    }

    /**
     * @return array{attributeId: string, title: string, valueId: string, value: string, hex: string|null}
     */
    private function colour(string $valueId, string $value): array
    {
        return [
            'attributeId' => 'attr-colour',
            'title' => 'Farbe',
            'valueId' => $valueId,
            'value' => $value,
            'hex' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tablesNamed(string $name): array
    {
        return DatabaseHelper::fetchAll(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name",
            [':name' => $name]
        );
    }
}
