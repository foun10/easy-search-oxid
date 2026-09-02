<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Unit\Index;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\ColorGrouper;
use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Core\ModuleSettings;
use foun10\EasySearch\Correction\Normalizer;
use foun10\EasySearch\Index\DiscountResolver;
use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Index\VisibilityResolver;
use foun10\EasySearch\Tests\Unit\Double\TestableDocumentProvider;
use PHPUnit\Framework\TestCase;

/**
 * Turning the catalogue into index documents.
 *
 * The expensive, shop-specific half of indexing, and the half a search backend
 * never sees: which rows become documents, what a variant inherits, what the
 * search text is made of, which price is indexed. The four database seams are
 * answered from arrays here (see TestableDocumentProvider), so all of that can
 * be pinned without a shop - including the parts that are only visible in the
 * SQL, like keyset paging and the WHERE that keeps variant parents out.
 */
class DocumentProviderTest extends TestCase
{
    private TestableDocumentProvider $provider;

    /** @var string[] */
    private array $facetAttributeIds = [];

    /** @var string[] */
    private array $searchableAttributeIds = [];

    /** @var array<string, string> */
    private array $displayModes = [];

    /** @var array<string, ?string> */
    private array $colorGroups = [];

    /** @var array<string, float> */
    private array $resolvedPrices = [];

    private bool $useParentAttributes = false;

    private bool $visible = true;

    /** @var int[] Shop IDs the attribute configuration was asked about */
    private array $configurationReads = [];

    /** @var array<int, array{articles: array<int, array<string, mixed>>, shopId: int, langId: int}> */
    private array $discountInput = [];

    protected function setUp(): void
    {
        $discountResolver = $this->createMock(DiscountResolver::class);
        $discountResolver->method('resolve')->willReturnCallback(
            function (array $articles, int $shopId, int $langId): array {
                $this->discountInput[] = ['articles' => $articles, 'shopId' => $shopId, 'langId' => $langId];

                return $this->resolvedPrices;
            }
        );

        $attributeConfiguration = $this->createMock(AttributeConfiguration::class);
        $attributeConfiguration->method('getFacetAttributeIds')->willReturnCallback(
            function (int $shopId): array {
                $this->configurationReads[] = $shopId;

                return $this->facetAttributeIds;
            }
        );
        $attributeConfiguration->method('getSearchableAttributeIds')->willReturnCallback(
            fn (int $shopId): array => $this->searchableAttributeIds
        );
        $attributeConfiguration->method('getDisplayModes')->willReturnCallback(
            fn (int $shopId): array => $this->displayModes
        );

        $visibilityResolver = $this->createMock(VisibilityResolver::class);
        $visibilityResolver->method('isVisible')->willReturnCallback(fn (array $row): bool => $this->visible);

        $colorGrouper = $this->createMock(ColorGrouper::class);
        $colorGrouper->method('group')->willReturnCallback(fn (string $value): ?string => $this->colorGroups[$value] ?? null);

        $moduleSettings = $this->createMock(ModuleSettings::class);
        $moduleSettings->method('useParentAttributes')->willReturnCallback(fn (): bool => $this->useParentAttributes);

        $this->provider = new TestableDocumentProvider(
            new Normalizer(),
            $discountResolver,
            $attributeConfiguration,
            $visibilityResolver,
            $colorGrouper,
            $moduleSettings
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function articleRow(string $id, array $overrides = []): array
    {
        return $overrides + [
            'OXID' => $id,
            'OXPARENTID' => '',
            'OXTITLE' => 'Titel ' . $id,
            'OXVARSELECT' => '',
            'OXARTNUM' => '',
            'OXEAN' => '',
            'OXMPN' => '',
            'OXSHORTDESC' => '',
            'OXACTIVE' => 1,
            'OXSOLDAMOUNT' => 0,
            'OXINSERT' => '2024-01-05',
            'OXPRICE' => 10.0,
            'OXSTOCK' => 1,
            'OXSTOCKFLAG' => 1,
            'OXMANUFACTURERID' => '',
            'BRANDTITLE' => '',
            'OXLONGDESC' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributeRow(string $objectId, string $attributeId, string $value, string $title = 'Farbe'): array
    {
        return [
            'OXOBJECTID' => $objectId,
            'OXATTRID' => $attributeId,
            'OXVALUE' => $value,
            'OXPOS' => 1,
            'OXTITLE' => $title,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{documents: IndexDocument[], lastId: string}
     */
    private function batchFrom(array $rows, int $shopId = 1, int $langId = 0): array
    {
        $this->provider->articleBatches = [$rows];

        return $this->provider->provideBatch($shopId, $langId, '', 500);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function documentFrom(array $rows, int $shopId = 1, int $langId = 0): IndexDocument
    {
        return $this->batchFrom($rows, $shopId, $langId)['documents'][0];
    }

    // ---------------------------------------------------------------
    // batching and the cursor
    // ---------------------------------------------------------------

    public function testEveryArticleRowBecomesOneDocument(): void
    {
        $batch = $this->batchFrom([$this->articleRow('a-1'), $this->articleRow('a-2')]);

        $this->assertCount(2, $batch['documents']);
        $this->assertSame('a-1', $batch['documents'][0]->getArticleId());
        $this->assertSame('a-2', $batch['documents'][1]->getArticleId());
    }

    public function testTheCursorIsTheLastArticleOfTheBatch(): void
    {
        $batch = $this->batchFrom([$this->articleRow('a-1'), $this->articleRow('a-2')]);

        $this->assertSame('a-2', $batch['lastId']);
    }

    /**
     * The end of the catalogue: no documents, and the cursor stays where it
     * was rather than resetting the run to the beginning.
     */
    public function testAnEmptyBatchKeepsTheCursor(): void
    {
        $this->provider->articleBatches = [[]];

        $batch = $this->provider->provideBatch(1, 0, 'a-7', 500);

        $this->assertSame([], $batch['documents']);
        $this->assertSame('a-7', $batch['lastId']);
    }

    public function testProvideStreamsBatchesUntilOneComesBackEmpty(): void
    {
        $this->provider->articleBatches = [
            [$this->articleRow('a-1'), $this->articleRow('a-2')],
            [$this->articleRow('a-3')],
        ];

        $articleIds = [];

        foreach ($this->provider->provide(1, 0, 2) as $document) {
            $articleIds[] = $document->getArticleId();
        }

        $this->assertSame(['a-1', 'a-2', 'a-3'], $articleIds);
        $this->assertSame(3, $this->provider->articleBatchReads, 'one read past the end to see it is the end');
    }

    /**
     * Keyset paging: each batch seeks past the last ID instead of growing an
     * OFFSET the database would have to walk and discard.
     */
    public function testEachBatchSeeksPastTheLastIdOfThePrevious(): void
    {
        $this->provider->articleBatches = [
            [$this->articleRow('a-1'), $this->articleRow('a-2')],
            [$this->articleRow('a-3')],
        ];

        iterator_to_array($this->provider->provide(1, 0, 2));

        $queries = $this->provider->queriesAgainst('oxarticles');

        $this->assertStringStartsWith('SELECT', ltrim($queries[0]));
        $this->assertStringContainsString("a.OXID > ''", $queries[0]);
        $this->assertStringContainsString("a.OXID > 'a-2'", $queries[1]);
        $this->assertStringNotContainsString('OFFSET', $queries[0]);
    }

    public function testTheBatchSizeIsTheLimit(): void
    {
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(1, 0, '', 250);

        $this->assertStringContainsString('LIMIT 250', $this->provider->queriesAgainst('oxarticles')[0]);
    }

    /**
     * A reindex that does not name a batch size gets one that has been run
     * against a real catalogue, not an accidental one.
     */
    public function testTheDefaultBatchSizeIsFiveHundred(): void
    {
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];

        iterator_to_array($this->provider->provide(1, 0));

        $this->assertStringContainsString('LIMIT 500', $this->provider->queriesAgainst('oxarticles')[0]);
    }

    /**
     * The shop-specific price override in oxfield2shop is joined per shop, so
     * the scope has to reach the statement as a parameter.
     */
    public function testTheArticleQueryIsBoundToTheShopWhereItOverridesPrices(): void
    {
        $this->provider->hasShopPriceOverrides = true;
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(4, 2, '', 500);

        $sql = $this->provider->queriesAgainst('oxarticles')[0];
        $this->assertStringContainsString('LEFT JOIN oxfield2shop AS f', $sql);
        $this->assertStringContainsString('IF (f.OXPRICE IS NOT NULL AND f.OXPRICE != 0, f.OXPRICE, a.OXPRICE)', $sql);
        $this->assertSame([':shopId' => 4], $this->provider->parametersFor('oxarticles'));
    }

    /**
     * oxfield2shop is an Enterprise table. Joining it on CE or PE fails the
     * very first batch of a rebuild with "Table 'oxfield2shop' doesn't exist",
     * which is how this was found - so the join is written only where the
     * table is.
     */
    public function testAnInstallationWithoutShopPriceOverridesJoinsNothing(): void
    {
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(4, 2, '', 500);

        $sql = $this->provider->queriesAgainst('oxarticles')[0];
        $this->assertStringNotContainsString('oxfield2shop', $sql);
        $this->assertStringContainsString('a.OXPRICE', $sql);
    }

    /**
     * A variant in OXID carries none of its own text: title, descriptions,
     * manufacturer, EAN and MPN all live on the parent row, and the shop's
     * Article model resolves them at runtime. Reading the variant row alone
     * indexes a product without its own name - which is exactly what happened,
     * and it meant that searching any product that has variants found nothing.
     *
     * Only the variants are indexed (parents are excluded on purpose), so
     * there is no second row to fall back on: the join has to do it.
     */
    public function testAVariantIsIndexedWithTheTextItInheritsFromItsParent(): void
    {
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(1, 0, '', 500);

        $sql = $this->provider->queriesAgainst('oxarticles')[0];

        $this->assertStringContainsString('LEFT JOIN oxv_oxarticles_1_0 AS p ON p.OXID = a.OXPARENTID', $sql);
        $this->assertStringContainsString('LEFT JOIN oxv_oxartextends_1_0 AS pe ON pe.OXID = a.OXPARENTID', $sql);

        foreach (['OXTITLE', 'OXARTNUM', 'OXEAN', 'OXMPN', 'OXSHORTDESC'] as $column) {
            $this->assertStringContainsString(
                sprintf("COALESCE(NULLIF(a.%1\$s, ''), p.%1\$s, '') AS %1\$s", $column),
                $sql,
                $column . ' does not fall back to the parent.'
            );
        }

        $this->assertStringContainsString(
            "COALESCE(NULLIF(e.OXLONGDESC, ''), pe.OXLONGDESC, '') AS OXLONGDESC",
            $sql
        );
    }

    /**
     * The brand has to be looked up under the id the variant ends up with, or
     * an inherited manufacturer id resolves to no brand name at all.
     */
    public function testTheBrandIsLookedUpUnderTheInheritedManufacturer(): void
    {
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(1, 0, '', 500);

        $sql = $this->provider->queriesAgainst('oxarticles')[0];
        $inherited = "COALESCE(NULLIF(a.OXMANUFACTURERID, ''), p.OXMANUFACTURERID, '')";

        $this->assertStringContainsString($inherited . ' AS OXMANUFACTURERID', $sql);
        $this->assertStringContainsString('AS m ON m.OXID = ' . $inherited, $sql);
    }

    /**
     * A bound name that appears in no statement is an error rather than a
     * spare, so the parameter goes where the join went.
     */
    public function testTheShopParameterIsDroppedWithTheJoin(): void
    {
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(4, 2, '', 500);

        $this->assertSame([], $this->provider->parametersFor('oxarticles'));
    }

    /**
     * A rebuild runs this query once per batch, and the edition does not
     * change between them.
     */
    public function testTheEditionIsAskedAboutOnlyOnce(): void
    {
        $this->provider->articleBatches = [
            [$this->articleRow('a-1')],
            [$this->articleRow('a-2')],
        ];

        $this->provider->provideBatch(1, 0, '', 500);
        $this->provider->provideBatch(1, 0, 'a-1', 500);

        $this->assertSame(1, $this->provider->editionChecks);
    }

    /**
     * A variant parent has no own stock and no size of its own; indexing it
     * would put a phantom hit next to its variants.
     */
    public function testVariantParentsAreNotRead(): void
    {
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(1, 0, '', 500);

        $this->assertStringContainsString(
            "a.OXACTIVE = 1 AND (a.OXPARENTID != '' OR a.OXVARCOUNT = 0)",
            $this->provider->queriesAgainst('oxarticles')[0]
        );
    }

    /**
     * The progress bar counts what the run will actually index - so the count
     * has to use the same filter the batches use, not a looser one.
     */
    public function testTheCountUsesTheSameFilterAsTheBatches(): void
    {
        $this->provider->articleCount = 42;
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(1, 0, '', 500);

        $this->assertSame(42, $this->provider->countArticles(1, 0));
        $this->assertStringStartsWith('SELECT COUNT(*)', $this->provider->countQueries[0]);
        $this->assertStringContainsString(
            "a.OXACTIVE = 1 AND (a.OXPARENTID != '' OR a.OXVARCOUNT = 0)",
            $this->provider->countQueries[0]
        );
    }

    /**
     * Reading is scoped by explicit IDs rather than by switching the shop
     * context, so one process can walk several subshops.
     */
    public function testEveryReadIsScopedToTheShopAndLanguage(): void
    {
        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(4, 2, '', 500);

        $this->assertStringContainsString('oxv_oxarticles_4_2', $this->provider->queriesAgainst('oxarticles')[0]);
    }

    // ---------------------------------------------------------------
    // the document
    // ---------------------------------------------------------------

    public function testAStandaloneArticleIsItsOwnGroup(): void
    {
        $document = $this->documentFrom([$this->articleRow('a-1')]);

        $this->assertSame('', $document->getParentId());
        $this->assertSame('a-1', $document->getGroupId());
    }

    /**
     * Results are collapsed on the group, so a customer sees one product
     * rather than twelve sizes of it.
     */
    public function testAVariantIsGroupedOnItsParent(): void
    {
        $document = $this->documentFrom([$this->articleRow('v-1', ['OXPARENTID' => 'p-1'])]);

        $this->assertSame('p-1', $document->getParentId());
        $this->assertSame('p-1', $document->getGroupId());
    }

    /**
     * One article appears in several shops and languages, so the scope has to
     * be part of the primary key.
     */
    public function testTheDocumentIdCarriesTheScope(): void
    {
        $document = $this->documentFrom([$this->articleRow('a-1')], 4, 2);

        $this->assertSame(md5('a-1_4_2'), $document->getId());
        $this->assertSame(4, $document->getShopId());
        $this->assertSame(2, $document->getLangId());
        $this->assertNotSame(
            $this->provider->buildDocumentIdPublic('a-1', 1, 0),
            $this->provider->buildDocumentIdPublic('a-1', 1, 1)
        );
    }

    public function testTheCatalogueFieldsAreCarriedOver(): void
    {
        $document = $this->documentFrom([$this->articleRow('a-1', [
            'OXTITLE' => 'Bikini-Top',
            'OXARTNUM' => '4711',
            'OXEAN' => '4006381333931',
            'OXMPN' => 'MPN-9',
            'BRANDTITLE' => 'Marke',
            'OXMANUFACTURERID' => 'm-1',
            'OXSTOCK' => '3',
            'OXSOLDAMOUNT' => '17',
        ])]);

        $this->assertSame('Bikini-Top', $document->getTitle());
        $this->assertSame('4711', $document->getArtNum());
        $this->assertSame('4006381333931', $document->getEan());
        $this->assertSame('MPN-9', $document->getMpn());
        $this->assertSame('Marke', $document->getBrand());
        $this->assertSame('m-1', $document->getManufacturerId());
        $this->assertSame(3.0, $document->getStock());
        $this->assertSame(17, $document->getSoldAmount());
    }

    public function testVisibilityIsLeftToTheResolver(): void
    {
        $this->visible = false;

        $this->assertFalse($this->documentFrom([$this->articleRow('a-1')])->isVisible());
    }

    /**
     * @dataProvider insertDateProvider
     */
    public function testTheInsertDateIsOnlyKeptWhenItIsOne(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->provider->toDateOrNullPublic($value));
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public function insertDateProvider(): array
    {
        return [
            'a date' => ['2024-01-05', '2024-01-05'],
            'a datetime' => ['2024-01-05 10:00:00', '2024-01-05 10:00:00'],
            'padded' => ['  2024-01-05  ', '2024-01-05'],
            // OXINSERT is NOT NULL and defaults to this. It is truthy but not a
            // date, and it fails the whole insert under strict mode.
            'the zero date' => ['0000-00-00', null],
            'the zero datetime' => ['0000-00-00 00:00:00', null],
            'empty' => ['', null],
            'missing' => [null, null],
        ];
    }

    public function testTheZeroDateNeverReachesADocument(): void
    {
        $document = $this->documentFrom([$this->articleRow('a-1', ['OXINSERT' => '0000-00-00'])]);

        $this->assertNull($document->getInsertDate());
    }

    // ---------------------------------------------------------------
    // price
    // ---------------------------------------------------------------

    /**
     * Sorting and price filtering are only meaningful on the price a customer
     * actually pays.
     */
    public function testTheIndexedPriceIsTheDiscountedOne(): void
    {
        $this->resolvedPrices = ['a-1' => 7.5];

        $this->assertSame(7.5, $this->documentFrom([$this->articleRow('a-1', ['OXPRICE' => 10.0])])->getPrice());
    }

    public function testWithoutADiscountTheCatalogPriceStands(): void
    {
        $this->assertSame(10.0, $this->documentFrom([$this->articleRow('a-1', ['OXPRICE' => '10.00'])])->getPrice());
    }

    /**
     * Category discounts are matched on the group's categories, because that
     * is where the assignment lives - a variant carries none of its own.
     */
    public function testTheResolverIsGivenTheGroupsCategories(): void
    {
        $this->provider->categoryAssignmentRows = [
            ['OXOBJECTID' => 'p-1', 'OXCATNID' => 'c-1'],
        ];

        $this->batchFrom([$this->articleRow('v-1', ['OXPARENTID' => 'p-1', 'OXPRICE' => '10.00'])], 4, 2);

        $this->assertSame(
            [['articleId' => 'v-1', 'parentId' => 'p-1', 'categoryIds' => ['c-1'], 'price' => 10.0]],
            $this->discountInput[0]['articles']
        );
        $this->assertSame(4, $this->discountInput[0]['shopId']);
        $this->assertSame(2, $this->discountInput[0]['langId']);
    }

    // ---------------------------------------------------------------
    // attributes
    // ---------------------------------------------------------------

    public function testOnlyConfiguredFacetAttributesBecomeFacetValues(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->provider->attributeRows = [
            $this->attributeRow('a-1', 'at-material', 'Baumwolle', 'Material'),
            $this->attributeRow('a-1', 'at-color', 'Rot', '  Farbe  '),
        ];

        $attributes = $this->documentFrom([$this->articleRow('a-1')])->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertSame('at-color', $attributes[0]['attributeId']);
        $this->assertSame('Rot', $attributes[0]['value']);
        $this->assertSame('Farbe', $attributes[0]['title'], 'the label is trimmed');
    }

    /**
     * The value ID is what a filter URL carries, so it has to survive a
     * reindex and a differently written value.
     */
    public function testTheValueIdIsStableAcrossSpelling(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->provider->attributeRows = [$this->attributeRow('a-1', 'at-color', '  RÖT-lich ')];

        $attributes = $this->documentFrom([$this->articleRow('a-1')])->getAttributes();

        $this->assertSame(md5('roet lich'), $attributes[0]['valueId']);
        $this->assertSame('RÖT-lich', $attributes[0]['value'], 'the value itself is only trimmed');
    }

    public function testAttributesWithoutAValueAreDropped(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->provider->attributeRows = [
            $this->attributeRow('a-1', 'at-color', '   '),
            $this->attributeRow('a-1', 'at-color', 'Rot'),
        ];

        $attributes = $this->documentFrom([$this->articleRow('a-1')])->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertSame('Rot', $attributes[0]['value']);
    }

    /**
     * Size sits on the variant, material usually on the parent - so a variant
     * inherits, but only when the catalogue is written that way.
     */
    public function testAVariantInheritsTheParentsAttributesWhenTheSettingIsOn(): void
    {
        $this->useParentAttributes = true;
        $this->facetAttributeIds = ['at-size', 'at-material'];
        $this->provider->attributeRows = [
            $this->attributeRow('p-1', 'at-material', 'Baumwolle', 'Material'),
            $this->attributeRow('v-1', 'at-size', '40', 'Groesse'),
        ];

        $attributes = $this->documentFrom([$this->articleRow('v-1', ['OXPARENTID' => 'p-1'])])->getAttributes();

        $this->assertSame(
            ['at-material', 'at-size'],
            array_column($attributes, 'attributeId'),
            'the parent first, so the variant\'s own values come last'
        );
    }

    public function testWithoutTheSettingAVariantKeepsOnlyItsOwnAttributes(): void
    {
        $this->facetAttributeIds = ['at-size', 'at-material'];
        $this->provider->attributeRows = [
            $this->attributeRow('p-1', 'at-material', 'Baumwolle', 'Material'),
            $this->attributeRow('v-1', 'at-size', '40', 'Groesse'),
        ];

        $attributes = $this->documentFrom([$this->articleRow('v-1', ['OXPARENTID' => 'p-1'])])->getAttributes();

        $this->assertSame(['at-size'], array_column($attributes, 'attributeId'));
    }

    /**
     * The join over oxobject2attribute is the biggest one in the indexer, so
     * parent rows are not even asked for unless they are wanted.
     */
    public function testParentIdsAreOnlyQueriedWhenTheirAttributesAreWanted(): void
    {
        $this->batchFrom([$this->articleRow('v-1', ['OXPARENTID' => 'p-1'])]);

        $this->assertStringContainsString("OXOBJECTID IN ('v-1')", $this->provider->queriesAgainst('oxobject2attribute')[0]);

        $this->setUp();
        $this->useParentAttributes = true;
        $this->batchFrom([$this->articleRow('v-1', ['OXPARENTID' => 'p-1'])]);

        $this->assertStringContainsString("'v-1', 'p-1'", $this->provider->queriesAgainst('oxobject2attribute')[0]);
    }

    /**
     * Same idea one level down: with a searchable list configured, the query
     * only asks for attributes something will actually look at.
     */
    public function testTheAttributeQueryIsRestrictedToTheConfiguredAttributes(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->searchableAttributeIds = ['at-material'];

        $this->batchFrom([$this->articleRow('a-1')]);

        $this->assertStringContainsString(
            "o2a.OXATTRID IN ('at-color', 'at-material')",
            $this->provider->queriesAgainst('oxobject2attribute')[0]
        );
    }

    /**
     * An empty searchable list means "everything is searchable", so nothing
     * can be excluded and the restriction has to be dropped entirely.
     */
    public function testWithoutASearchableListNoAttributeIsExcluded(): void
    {
        $this->facetAttributeIds = ['at-color'];

        $this->assertNull($this->provider->getWantedAttributeIdsPublic(1));

        $this->batchFrom([$this->articleRow('a-1')]);

        $this->assertStringNotContainsString('OXATTRID IN', $this->provider->queriesAgainst('oxobject2attribute')[0]);
    }

    public function testTheWantedListIsTheUnionOfBothConfigurations(): void
    {
        $this->facetAttributeIds = ['at-color', 'at-size'];
        $this->searchableAttributeIds = ['at-color', 'at-material'];

        $this->assertSame(
            ['at-color', 'at-size', 'at-material'],
            $this->provider->getWantedAttributeIdsPublic(1)
        );
    }

    // ---------------------------------------------------------------
    // colour grouping
    // ---------------------------------------------------------------

    public function testAColorGroupedAttributeIsReplacedByItsGroup(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->displayModes = ['at-color' => FacetDisplay::MODE_GROUPED_COLOR_TILE];
        $this->colorGroups = ['Tomatencreme_#D32F2F' => 'Rot_#D32F2F'];
        $this->provider->attributeRows = [$this->attributeRow('a-1', 'at-color', 'Tomatencreme_#D32F2F')];

        $attributes = $this->documentFrom([$this->articleRow('a-1')])->getAttributes();

        $this->assertSame('Rot_#D32F2F', $attributes[0]['value']);
        $this->assertSame(md5('rot d32f2f'), $attributes[0]['valueId'], 'the ID follows the group, not the original');
    }

    /**
     * No hex code, nothing to judge - the honest outcome is to leave the value
     * exactly as the catalogue wrote it.
     */
    public function testAValueTheGrouperCannotReadIsKept(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->displayModes = ['at-color' => FacetDisplay::MODE_GROUPED_COLOR_TILE];
        $this->provider->attributeRows = [$this->attributeRow('a-1', 'at-color', 'Tomatencreme')];

        $attributes = $this->documentFrom([$this->articleRow('a-1')])->getAttributes();

        $this->assertSame('Tomatencreme', $attributes[0]['value']);
        $this->assertSame(md5('tomatencreme'), $attributes[0]['valueId']);
    }

    public function testTwoColoursOfOneGroupContributeItOnce(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->displayModes = ['at-color' => FacetDisplay::MODE_GROUPED_COLOR_TILE];
        $this->colorGroups = [
            'Tomatencreme_#D32F2F' => 'Rot_#D32F2F',
            'Bordeaux_#C62828' => 'Rot_#D32F2F',
            'Azur_#1E88E5' => 'Blau_#1E88E5',
        ];
        $this->provider->attributeRows = [
            $this->attributeRow('a-1', 'at-color', 'Tomatencreme_#D32F2F'),
            $this->attributeRow('a-1', 'at-color', 'Bordeaux_#C62828'),
            $this->attributeRow('a-1', 'at-color', 'Azur_#1E88E5'),
        ];

        $attributes = $this->documentFrom([$this->articleRow('a-1')])->getAttributes();

        $this->assertSame(
            ['Rot_#D32F2F', 'Blau_#1E88E5'],
            array_column($attributes, 'value'),
            'a repeat is skipped, not the end of the list'
        );
    }

    /**
     * Only the facet copy is grouped. A customer searching for "Tomatencreme"
     * still has to find the product the filter offers as "Rot".
     */
    public function testGroupingLeavesTheSearchTextAlone(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->displayModes = ['at-color' => FacetDisplay::MODE_GROUPED_COLOR_TILE];
        $this->colorGroups = ['Tomatencreme_#D32F2F' => 'Rot_#D32F2F'];
        $this->provider->attributeRows = [$this->attributeRow('a-1', 'at-color', 'Tomatencreme_#D32F2F')];

        $document = $this->documentFrom([$this->articleRow('a-1')]);

        $this->assertStringContainsString('tomatencreme', $document->getSearchText());
        $this->assertSame('Rot_#D32F2F', $document->getAttributes()[0]['value']);
    }

    public function testAttributesAreLeftAloneWhenNothingIsGrouped(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->displayModes = ['at-color' => FacetDisplay::MODE_COLOR];
        $this->provider->attributeRows = [$this->attributeRow('a-1', 'at-color', 'Tomatencreme_#D32F2F')];

        $this->assertSame(
            'Tomatencreme_#D32F2F',
            $this->documentFrom([$this->articleRow('a-1')])->getAttributes()[0]['value']
        );
    }

    // ---------------------------------------------------------------
    // categories
    // ---------------------------------------------------------------

    public function testACategoryPathIsBuiltFromTheTree(): void
    {
        $this->provider->categoryAssignmentRows = [['OXOBJECTID' => 'a-1', 'OXCATNID' => 'c-3']];
        $this->provider->categoryRows = [
            ['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Damen'],
            ['OXID' => 'c-2', 'OXPARENTID' => 'c-1', 'OXTITLE' => 'Waesche'],
            ['OXID' => 'c-3', 'OXPARENTID' => 'c-2', 'OXTITLE' => 'BHs'],
        ];

        $document = $this->documentFrom([$this->articleRow('a-1')]);

        $this->assertSame(['Damen > Waesche > BHs'], $document->getCategoryPaths());
        $this->assertSame(['c-3'], $document->getCategoryIds(), 'the raw IDs travel along for discount matching');
    }

    public function testACategoryTheTreeDoesNotKnowIsSkipped(): void
    {
        $this->provider->categoryAssignmentRows = [
            ['OXOBJECTID' => 'a-1', 'OXCATNID' => 'c-1'],
            ['OXOBJECTID' => 'a-1', 'OXCATNID' => 'c-gone'],
        ];
        $this->provider->categoryRows = [
            ['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Damen'],
        ];

        $document = $this->documentFrom([$this->articleRow('a-1')]);

        $this->assertSame(['Damen'], $document->getCategoryPaths());
        $this->assertSame(['c-1', 'c-gone'], $document->getCategoryIds());
    }

    /**
     * Category assignments live on the parent, so a variant is looked up under
     * its group.
     */
    public function testAVariantTakesTheCategoriesOfItsGroup(): void
    {
        $this->provider->categoryAssignmentRows = [['OXOBJECTID' => 'p-1', 'OXCATNID' => 'c-1']];
        $this->provider->categoryRows = [['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Damen']];

        $document = $this->documentFrom([$this->articleRow('v-1', ['OXPARENTID' => 'p-1'])]);

        $this->assertSame(['Damen'], $document->getCategoryPaths());
        $this->assertStringContainsString("OXOBJECTID IN ('p-1')", $this->provider->queriesAgainst('oxobject2category')[0]);
    }

    /**
     * The tree is small enough to resolve once and keep - resolving it per
     * product is what made the export this replaced slow.
     */
    public function testTheCategoryTreeIsReadOncePerScope(): void
    {
        $this->provider->categoryRows = [['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Damen']];
        $this->provider->articleBatches = [
            [$this->articleRow('a-1'), $this->articleRow('a-2')],
            [$this->articleRow('a-3')],
        ];

        iterator_to_array($this->provider->provide(1, 0, 2));

        $this->assertSame(1, $this->provider->categoryTreeReads);

        $this->provider->articleBatches = [[$this->articleRow('a-4')]];
        $this->provider->provideBatch(2, 0, '', 500);

        $this->assertSame(2, $this->provider->categoryTreeReads, 'another shop is another tree');

        $this->provider->articleBatches = [[$this->articleRow('a-5')]];
        $this->provider->provideBatch(2, 1, '', 500);

        $this->assertSame(3, $this->provider->categoryTreeReads, 'and so is another language');
    }

    /**
     * Shop 1 in language 12 and shop 11 in language 2 are different scopes and
     * must not share the cached tree - which they would if the key were the
     * two numbers written next to each other.
     */
    public function testScopesThatWriteTheSameDigitsAreStillDifferent(): void
    {
        $this->provider->categoryRows = [['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Damen']];

        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(1, 12, '', 500);

        $this->provider->articleBatches = [[$this->articleRow('a-2')]];
        $this->provider->provideBatch(11, 2, '', 500);

        $this->assertSame(2, $this->provider->categoryTreeReads);
    }

    public function testTheRootEndsAPath(): void
    {
        $path = $this->provider->buildCategoryPathPublic('c-1', [
            'c-1' => ['parentId' => 'oxrootid', 'title' => 'Damen'],
        ]);

        $this->assertSame('Damen', $path);
    }

    public function testAnEmptyParentEndsAPath(): void
    {
        $path = $this->provider->buildCategoryPathPublic('c-2', [
            'c-1' => ['parentId' => '', 'title' => 'Damen'],
            'c-2' => ['parentId' => 'c-1', 'title' => 'Waesche'],
        ]);

        $this->assertSame('Damen > Waesche', $path);
    }

    /**
     * A cyclic parent reference in the data would otherwise hang the whole
     * reindex, so the walk is bounded - at twenty levels, which is far deeper
     * than any real category tree.
     */
    public function testACyclicCategoryStopsAtTheGuard(): void
    {
        $path = $this->provider->buildCategoryPathPublic('c-1', [
            'c-1' => ['parentId' => 'c-2', 'title' => 'Damen'],
            'c-2' => ['parentId' => 'c-1', 'title' => 'Waesche'],
        ]);

        $this->assertCount(20, explode(' > ', $path));
    }

    public function testCategoryTitlesAreTrimmed(): void
    {
        $this->provider->categoryAssignmentRows = [['OXOBJECTID' => 'a-1', 'OXCATNID' => 'c-2']];
        $this->provider->categoryRows = [
            ['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => '  Damen '],
            ['OXID' => 'c-2', 'OXPARENTID' => 'c-1', 'OXTITLE' => 'Waesche  '],
        ];

        $this->assertSame(
            ['Damen > Waesche'],
            $this->documentFrom([$this->articleRow('a-1')])->getCategoryPaths()
        );
    }

    /**
     * A category without a title in this language would otherwise leave an
     * empty step - "Damen >  > BHs" - in the searchable text.
     */
    public function testACategoryWithoutATitleDoesNotLeaveAGap(): void
    {
        $this->provider->categoryAssignmentRows = [['OXOBJECTID' => 'a-1', 'OXCATNID' => 'c-3']];
        $this->provider->categoryRows = [
            ['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Damen'],
            ['OXID' => 'c-2', 'OXPARENTID' => 'c-1', 'OXTITLE' => ''],
            ['OXID' => 'c-3', 'OXPARENTID' => 'c-2', 'OXTITLE' => 'BHs'],
        ];

        $this->assertSame(
            ['Damen > BHs'],
            $this->documentFrom([$this->articleRow('a-1')])->getCategoryPaths()
        );
    }

    // ---------------------------------------------------------------
    // the text a search runs against
    // ---------------------------------------------------------------

    /**
     * The boost text is what a match is weighted by: the fields that identify
     * the product, and nothing else.
     */
    public function testTheBoostTextIsTheIdentifyingFields(): void
    {
        $document = $this->documentFrom([$this->articleRow('a-1', [
            'OXTITLE' => 'Bikini-Top',
            'BRANDTITLE' => 'Marke',
            'OXVARSELECT' => '40 B',
            'OXARTNUM' => '4711',
            'OXEAN' => '4006381333931',
            'OXMPN' => 'MPN-9',
            'OXSHORTDESC' => 'Kurzbeschreibung',
        ])]);

        $this->assertSame('bikini top marke 40 b 4711 4006381333931 mpn 9', $document->getBoostText());
        $this->assertStringNotContainsString('kurzbeschreibung', $document->getBoostText());
    }

    /**
     * The column it is written to is bounded, and a description that long
     * says nothing about the product anyway.
     */
    public function testTheBoostTextIsCappedInCharactersNotBytes(): void
    {
        $document = $this->documentFrom([$this->articleRow('a-1', ['OXTITLE' => str_repeat('ж', 2000)])]);

        $this->assertSame(1024, mb_strlen($document->getBoostText()));
    }

    public function testTheSearchTextAddsEverythingElseWorthMatching(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->provider->attributeRows = [$this->attributeRow('a-1', 'at-color', 'Rot')];
        $this->provider->categoryAssignmentRows = [['OXOBJECTID' => 'a-1', 'OXCATNID' => 'c-1']];
        $this->provider->categoryRows = [['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Waesche']];

        $document = $this->documentFrom([$this->articleRow('a-1', [
            'OXTITLE' => 'Bikini-Top',
            'OXSHORTDESC' => 'Kurz',
            'OXLONGDESC' => '<p>Lang <b>beschrieben</b></p>',
        ])]);

        $this->assertSame('bikini top waesche rot kurz lang beschrieben', $document->getSearchText());
    }

    /**
     * A long description is HTML. Without stripping it, a search for "div" or
     * "href" would match half the catalogue.
     */
    public function testMarkupIsStrippedFromTheLongDescription(): void
    {
        $document = $this->documentFrom([$this->articleRow('a-1', [
            'OXTITLE' => 'Top',
            'OXLONGDESC' => '<div class="teaser"><a href="/damen">Damen</a></div>',
        ])]);

        $this->assertSame('top damen', $document->getSearchText());
    }

    /**
     * Attributes only feed the text when they are searchable - the point of
     * the setting is to keep noise like a warehouse code out of it.
     */
    public function testOnlySearchableAttributesFeedTheSearchText(): void
    {
        $this->facetAttributeIds = ['at-color', 'at-lager'];
        $this->searchableAttributeIds = ['at-color'];
        $this->provider->attributeRows = [
            $this->attributeRow('a-1', 'at-color', 'Rot'),
            $this->attributeRow('a-1', 'at-lager', 'Regal 12', 'Lagerplatz'),
        ];

        $document = $this->documentFrom([$this->articleRow('a-1', ['OXTITLE' => 'Top'])]);

        $this->assertSame('top rot', $document->getSearchText());
        $this->assertCount(2, $document->getAttributes(), 'both are still facets');
    }

    // ---------------------------------------------------------------
    // scope
    // ---------------------------------------------------------------

    /**
     * The attribute configuration is per shop and would otherwise be read once
     * per document.
     */
    public function testTheConfigurationIsReadOncePerScope(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->provider->articleBatches = [
            [$this->articleRow('a-1'), $this->articleRow('a-2')],
            [$this->articleRow('a-3')],
        ];

        iterator_to_array($this->provider->provide(1, 0, 2));

        $this->assertSame([1], $this->configurationReads);
    }

    /**
     * ... but it must not leak into the next shop, whose facets are its own.
     */
    public function testAnotherShopRereadsTheConfiguration(): void
    {
        $this->facetAttributeIds = ['at-color'];

        $this->provider->articleBatches = [[$this->articleRow('a-1')]];
        $this->provider->provideBatch(1, 0, '', 500);

        $this->provider->articleBatches = [[$this->articleRow('a-2')]];
        $this->provider->provideBatch(2, 0, '', 500);

        $this->assertSame([1, 2], $this->configurationReads);
    }

    // ---------------------------------------------------------------
    // what a facet value is keyed by
    // ---------------------------------------------------------------

    /**
     * Two attributes can carry the same value - "Farbe: Rot" and "Naht: Rot"
     * share a value ID, because that ID is the value alone. Only the pair
     * identifies a selection.
     */
    public function testTwoAttributesSharingAValueBothSurvive(): void
    {
        $this->facetAttributeIds = ['at-color', 'at-seam'];
        $this->provider->attributeRows = [
            $this->attributeRow('a-1', 'at-color', 'Rot'),
            $this->attributeRow('a-1', 'at-seam', 'Rot', 'Naht'),
        ];

        $attributes = $this->documentFrom([$this->articleRow('a-1')])->getAttributes();

        $this->assertSame(['at-color', 'at-seam'], array_column($attributes, 'attributeId'));
        $this->assertSame(
            $attributes[0]['valueId'],
            $attributes[1]['valueId'],
            'the value ID is the value, not the pair'
        );
    }

    public function testTwoValuesOfOneAttributeBothSurvive(): void
    {
        $this->facetAttributeIds = ['at-color'];
        $this->provider->attributeRows = [
            $this->attributeRow('a-1', 'at-color', 'Rot'),
            $this->attributeRow('a-1', 'at-color', 'Blau'),
        ];

        $attributes = $this->documentFrom([$this->articleRow('a-1')])->getAttributes();

        $this->assertSame(['Rot', 'Blau'], array_column($attributes, 'value'));
    }

    /**
     * An ERP that writes the material on the parent *and* on every variant is
     * ordinary. Indexed twice, the facet would count one product as two.
     */
    public function testAnInheritedAttributeTheVariantAlsoCarriesIsIndexedOnce(): void
    {
        $this->useParentAttributes = true;
        $this->facetAttributeIds = ['at-material'];
        $this->provider->attributeRows = [
            $this->attributeRow('p-1', 'at-material', 'Baumwolle', 'Material'),
            $this->attributeRow('v-1', 'at-material', 'Baumwolle', 'Material'),
        ];

        $attributes = $this->documentFrom([$this->articleRow('v-1', ['OXPARENTID' => 'p-1'])])->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertSame('Baumwolle', $attributes[0]['value']);
    }

    /**
     * A numeric attribute ID arrives as an int - from the configuration as a
     * value, from the display modes as an array key. Both are compared
     * strictly against the string the catalogue row carries.
     */
    public function testANumericAttributeIdIsStillMatched(): void
    {
        $this->facetAttributeIds = [4711];
        $this->displayModes = [4711 => FacetDisplay::MODE_GROUPED_COLOR_TILE];
        $this->colorGroups = ['Tomatencreme_#D32F2F' => 'Rot_#D32F2F'];
        $this->provider->attributeRows = [$this->attributeRow('a-1', '4711', 'Tomatencreme_#D32F2F')];

        $attributes = $this->documentFrom([$this->articleRow('a-1')])->getAttributes();

        $this->assertCount(1, $attributes, 'the facet list matched');
        $this->assertSame('Rot_#D32F2F', $attributes[0]['value'], 'and so did the display mode');
    }

    /**
     * The same for the searchable list, which decides what feeds the text.
     */
    public function testANumericSearchableAttributeIdIsStillMatched(): void
    {
        $this->facetAttributeIds = [4711];
        $this->searchableAttributeIds = [4711];
        $this->provider->attributeRows = [$this->attributeRow('a-1', '4711', 'Rot')];

        $document = $this->documentFrom([$this->articleRow('a-1', ['OXTITLE' => 'Top'])]);

        $this->assertSame('top rot', $document->getSearchText());
    }

    // ---------------------------------------------------------------
    // more than one of everything
    // ---------------------------------------------------------------

    public function testEveryKnownCategoryBecomesAPath(): void
    {
        $this->provider->categoryAssignmentRows = [
            ['OXOBJECTID' => 'a-1', 'OXCATNID' => 'c-1'],
            ['OXOBJECTID' => 'a-1', 'OXCATNID' => 'c-2'],
        ];
        $this->provider->categoryRows = [
            ['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Damen'],
            ['OXID' => 'c-2', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Sale'],
        ];

        $document = $this->documentFrom([$this->articleRow('a-1')]);

        $this->assertSame(['Damen', 'Sale'], $document->getCategoryPaths());
        $this->assertSame(['c-1', 'c-2'], $document->getCategoryIds());
    }

    public function testEachArticleKeepsItsOwnCategories(): void
    {
        $this->provider->categoryAssignmentRows = [
            ['OXOBJECTID' => 'a-1', 'OXCATNID' => 'c-1'],
            ['OXOBJECTID' => 'a-2', 'OXCATNID' => 'c-2'],
        ];
        $this->provider->categoryRows = [
            ['OXID' => 'c-1', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Damen'],
            ['OXID' => 'c-2', 'OXPARENTID' => 'oxrootid', 'OXTITLE' => 'Herren'],
        ];

        $documents = $this->batchFrom([$this->articleRow('a-1'), $this->articleRow('a-2')])['documents'];

        $this->assertSame(['Damen'], $documents[0]->getCategoryPaths());
        $this->assertSame(['Herren'], $documents[1]->getCategoryPaths());
    }

    /**
     * Twelve sizes of one product share one parent, and the parent's
     * categories and attributes are the same for all of them - so it is asked
     * for once, not twelve times.
     */
    public function testVariantsOfOneParentAreLookedUpOnce(): void
    {
        $this->batchFrom([
            $this->articleRow('v-1', ['OXPARENTID' => 'p-1']),
            $this->articleRow('v-2', ['OXPARENTID' => 'p-1']),
        ]);

        $this->assertStringContainsString(
            "OXOBJECTID IN ('p-1')",
            $this->provider->queriesAgainst('oxobject2category')[0]
        );
    }

    /**
     * Not every article has a manufacturer or a long description; both come
     * from LEFT JOINs, and OXPRICE can be absent as well. None of that is a
     * reason to skip the article.
     */
    public function testAnArticleWithoutTheOptionalColumnsIsStillIndexed(): void
    {
        $document = $this->documentFrom([[
            'OXID' => 'a-1',
            'OXPARENTID' => '',
            'OXTITLE' => 'Top',
            'OXACTIVE' => 1,
            'BRANDTITLE' => null,
            'OXLONGDESC' => null,
        ]]);

        $this->assertSame('', $document->getBrand());
        $this->assertSame('', $document->getManufacturerId());
        $this->assertSame('top', $document->getSearchText());
        $this->assertSame(0.0, $document->getPrice());
        $this->assertSame(0.0, $document->getStock());
        $this->assertSame(0, $document->getSoldAmount());
        $this->assertNull($document->getInsertDate());
        $this->assertSame(
            0.0,
            $this->discountInput[0]['articles'][0]['price'],
            'the resolver is handed the same zero, not a made up price'
        );
    }
}
