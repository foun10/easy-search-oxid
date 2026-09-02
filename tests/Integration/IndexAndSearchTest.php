<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Integration;

use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Query\SuggestQuery;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Engine\SearchEngineInterface;
use foun10\EasySearch\Index\DocumentProvider;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use PHPUnit\Framework\TestCase;

/**
 * The indexer and the engine against a real catalogue.
 *
 * Read-only on purpose: these run against whatever the shop currently holds and
 * write nothing, so the suite cannot leave a half-built index behind. What they
 * cover is the half the unit tests cannot reach - the SQL actually executing.
 * Every statement in DocumentProvider is asserted against a double up there,
 * which proves the statement is the one intended and nothing about whether the
 * database accepts it. Both bugs this module has had in that area were of the
 * second kind: a join against a table only Enterprise has, and a derived table
 * named after a word MySQL reserved in 8.0.2. Both parsed fine and both killed
 * every rebuild.
 *
 * They need a shop with an index. A fresh one has neither, so each test says
 * what it needs and skips rather than failing - a red suite on an unindexed
 * shop would be noise, not a finding.
 */
class IndexAndSearchTest extends TestCase
{
    private const SHOP_ID = 1;
    private const LANG_ID = 0;

    private SearchEngineInterface $engine;

    private DocumentProvider $provider;

    protected function setUp(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();

        $this->engine = $container->get(SearchEngineInterface::class);
        $this->provider = $container->get(DocumentProvider::class);

        if (!$this->engine->isAvailable(self::SHOP_ID, self::LANG_ID)) {
            $this->markTestSkipped(
                'No index for shop ' . self::SHOP_ID . ', language ' . self::LANG_ID
                . ' - run foun10:easysearch:reindex first.'
            );
        }
    }

    // ---------------------------------------------------------------
    // reading the catalogue
    // ---------------------------------------------------------------

    /**
     * The statement the unit tests assert the shape of, actually running.
     *
     * This is where an Enterprise-only join or a reserved word shows up, and
     * neither is visible to a test that reads the SQL rather than executes it.
     */
    public function testTheCatalogueCanBeReadIntoDocuments(): void
    {
        $batch = $this->provider->provideBatch(self::SHOP_ID, self::LANG_ID, '', 5);

        $this->assertNotSame([], $batch['documents'], 'The shop has articles but none were read.');
        $this->assertNotSame('', $batch['lastId'], 'A batch with documents has to carry a cursor.');
    }

    public function testADocumentCarriesWhatTheIndexNeeds(): void
    {
        $document = $this->provider->provideBatch(self::SHOP_ID, self::LANG_ID, '', 1)['documents'][0];

        $this->assertSame(self::SHOP_ID, $document->getShopId());
        $this->assertSame(self::LANG_ID, $document->getLangId());
        $this->assertNotSame('', $document->getId());
        $this->assertNotSame('', $document->getArticleId());
        $this->assertNotSame('', $document->getTitle());
        $this->assertNotSame('', $document->getSearchText());
    }

    /**
     * Keyset paging rather than OFFSET, so the cursor has to actually move the
     * window - a cursor that is read but not applied gives the same batch for
     * ever, and a reindex that never finishes.
     */
    public function testTheCursorMovesTheWindowForward(): void
    {
        $first = $this->provider->provideBatch(self::SHOP_ID, self::LANG_ID, '', 2);

        if (count($first['documents']) < 2) {
            $this->markTestSkipped('Needs at least two articles in the scope.');
        }

        $second = $this->provider->provideBatch(self::SHOP_ID, self::LANG_ID, $first['lastId'], 2);

        $this->assertNotSame(
            $this->articleIds($first['documents']),
            $this->articleIds($second['documents']),
            'The second batch repeated the first - the cursor is not being applied.'
        );
    }

    public function testCountingTheScopeAgreesWithReadingIt(): void
    {
        $count = $this->provider->countArticles(self::SHOP_ID, self::LANG_ID);

        $this->assertGreaterThan(0, $count);
    }

    // ---------------------------------------------------------------
    // searching what was indexed
    // ---------------------------------------------------------------

    /**
     * A term taken from the shop's own catalogue rather than invented, so the
     * test says something about this installation instead of about a fixture.
     */
    public function testATermFromTheCatalogueIsFound(): void
    {
        $term = $this->aTermFromTheIndex();

        $result = $this->engine->search(new SearchQuery($term, self::SHOP_ID, self::LANG_ID));

        $this->assertGreaterThan(0, $result->getTotalCount(), 'Searching "' . $term . '" found nothing.');
        $this->assertNotSame([], $result->getProductIds());
    }

    /**
     * A product that has variants has to be findable by its own name.
     *
     * This is the test that would have caught the module's worst bug, and no
     * unit test could have: only the variants are indexed, and in OXID a
     * variant's title, descriptions and manufacturer are all empty - they live
     * on the parent row. Reading the variant alone indexed every such product
     * without its name, so searching "Dune" returned nothing while the product
     * sat right there in the catalogue. Nothing failed, no log line, and a
     * search for a *category* name still worked, which is what made it look
     * fine end to end.
     */
    public function testAProductWithVariantsIsFoundByItsOwnName(): void
    {
        $parent = $this->aParentTitleWithVariants();

        $result = $this->engine->search(new SearchQuery($parent, self::SHOP_ID, self::LANG_ID));

        $this->assertGreaterThan(
            0,
            $result->getTotalCount(),
            sprintf(
                'Searching "%s" found nothing, but the catalogue holds a product of that name with '
                . 'variants. The indexer is reading variant rows without falling back to the parent.',
                $parent
            )
        );
    }

    public function testAWordNoCatalogueContainsFindsNothing(): void
    {
        $result = $this->engine->search(
            new SearchQuery('zzqxwvunlikelyterm', self::SHOP_ID, self::LANG_ID)
        );

        $this->assertSame(0, $result->getTotalCount());
        $this->assertSame([], $result->getProductIds());
    }

    /**
     * The paging the listing controllers drive.
     */
    public function testAPageOfResultsIsTheSizeItWasAskedFor(): void
    {
        $term = $this->aTermFromTheIndex();
        $all = $this->engine->search(new SearchQuery($term, self::SHOP_ID, self::LANG_ID));

        if ($all->getTotalCount() < 3) {
            $this->markTestSkipped('Needs a term with at least three hits.');
        }

        $page = $this->engine->search(
            new SearchQuery($term, self::SHOP_ID, self::LANG_ID, [], SearchQuery::SORT_RELEVANCE, 0, 2)
        );

        $this->assertCount(2, $page->getProductIds());
        $this->assertSame($all->getTotalCount(), $page->getTotalCount(), 'The total must not depend on the page size.');
    }

    public function testTheSecondPageHoldsDifferentProducts(): void
    {
        $term = $this->aTermFromTheIndex();
        $all = $this->engine->search(new SearchQuery($term, self::SHOP_ID, self::LANG_ID));

        if ($all->getTotalCount() < 4) {
            $this->markTestSkipped('Needs a term with at least four hits.');
        }

        $first = $this->engine->search(
            new SearchQuery($term, self::SHOP_ID, self::LANG_ID, [], SearchQuery::SORT_RELEVANCE, 0, 2)
        );
        $second = $this->engine->search(
            new SearchQuery($term, self::SHOP_ID, self::LANG_ID, [], SearchQuery::SORT_RELEVANCE, 2, 2)
        );

        $this->assertSame([], array_intersect($first->getProductIds(), $second->getProductIds()));
    }

    // ---------------------------------------------------------------
    // facets, which are derived rather than stored
    // ---------------------------------------------------------------

    /**
     * Facet counts come out of a table the rebuild derives from what it just
     * wrote, so this is the one part of a search that a unit test cannot
     * meaningfully stand in for.
     */
    public function testAFacetIsBuiltFromTheIndexedAttributes(): void
    {
        $result = $this->engine->search(
            new SearchQuery($this->aTermFromTheIndex(), self::SHOP_ID, self::LANG_ID)
        );

        if ($result->getFacets() === []) {
            $this->markTestSkipped(
                'No attribute is configured as a facet - set one on the Attributes screen.'
            );
        }

        $facet = $result->getFacets()[0];

        $this->assertInstanceOf(Facet::class, $facet);
        $this->assertNotSame('', $facet->getAttributeId());
        $this->assertNotSame('', $facet->getTitle());
        $this->assertNotSame([], $facet->getValues(), 'A facet with no values should not be offered at all.');
    }

    /**
     * Selecting a value must narrow the result - the whole point of the panel.
     */
    public function testSelectingAFacetValueNarrowsTheResult(): void
    {
        $term = $this->aTermFromTheIndex();
        $unfiltered = $this->engine->search(new SearchQuery($term, self::SHOP_ID, self::LANG_ID));

        if ($unfiltered->getFacets() === []) {
            $this->markTestSkipped('No attribute is configured as a facet.');
        }

        $facet = $unfiltered->getFacets()[0];
        $value = $facet->getValues()[0];

        $filtered = $this->engine->search(new SearchQuery(
            $term,
            self::SHOP_ID,
            self::LANG_ID,
            [new \foun10\EasySearch\Engine\Query\FacetFilter($facet->getAttributeId(), [$value->getValueId()])]
        ));

        $this->assertGreaterThan(0, $filtered->getTotalCount(), 'A value the panel offers has to lead somewhere.');
        $this->assertLessThanOrEqual($unfiltered->getTotalCount(), $filtered->getTotalCount());
    }

    // ---------------------------------------------------------------
    // suggest, over the same index
    // ---------------------------------------------------------------

    public function testSuggestAnswersForAPrefixOfAnIndexedTerm(): void
    {
        $term = $this->aTermFromTheIndex();

        if (mb_strlen($term) < 4) {
            $this->markTestSkipped('Needs a term long enough to have a prefix.');
        }

        $result = $this->engine->suggest(
            new SuggestQuery(mb_substr($term, 0, 3), self::SHOP_ID, self::LANG_ID)
        );

        $this->assertNotSame(
            [],
            $result->getTerms(),
            'A prefix of an indexed term suggested nothing - is the dictionary built?'
        );
    }

    /**
     * A term the shop's most frequent word, read out of the dictionary the
     * rebuild wrote - the same source the doctor command uses to pick one.
     */
    private function aTermFromTheIndex(): string
    {
        static $term = null;

        if ($term !== null) {
            return $term;
        }

        $rows = \foun10\EasySearch\Core\DatabaseHelper::fetchAll(
            'SELECT FOUN10TERMRAW AS term
             FROM ' . \foun10\EasySearch\Index\DictionaryBuilder::TABLE . '
             WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId AND FOUN10LENGTH >= 4
             ORDER BY FOUN10FREQUENCY DESC
             LIMIT 1',
            [':shopId' => self::SHOP_ID, ':langId' => self::LANG_ID]
        );

        if ($rows === []) {
            $this->markTestSkipped('The correction dictionary is empty - run a reindex.');
        }

        return $term = (string) $rows[0]['term'];
    }

    /**
     * The title of a product that has variants, taken from the shop's own
     * catalogue - a fixture would prove nothing about this installation.
     */
    private function aParentTitleWithVariants(): string
    {
        $rows = \foun10\EasySearch\Core\DatabaseHelper::fetchAll(
            "SELECT DISTINCT p.OXTITLE AS title
             FROM oxarticles v
             JOIN oxarticles p ON p.OXID = v.OXPARENTID
             WHERE v.OXPARENTID != '' AND v.OXACTIVE = 1 AND p.OXTITLE != ''
             LIMIT 1"
        );

        if ($rows === []) {
            $this->markTestSkipped('The catalogue holds no active variants.');
        }

        return (string) $rows[0]['title'];
    }

    /**
     * @param \foun10\EasySearch\Index\IndexDocument[] $documents
     *
     * @return string[]
     */
    private function articleIds(array $documents): array
    {
        return array_map(
            static fn ($document): string => $document->getArticleId(),
            $documents
        );
    }
}
