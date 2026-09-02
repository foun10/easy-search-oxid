<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Integration;

use foun10\EasySearch\Controller\SuggestController;
use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\SearchEngineInterface;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use PHPUnit\Framework\TestCase;

/**
 * The two steps of the suggest endpoint that need real products.
 *
 * The unit suite stands in for renderProducts() and renderCategories() rather
 * than testing them, and says so: they load OXID models and read the shop's own
 * pricing, visibility and link building, so faking that would mean testing the
 * fake. This is the other half of that decision - the part where the models are
 * real.
 *
 * What they have to get right is not the shape of the array, which the unit
 * tests already pin through the payload, but the things only a shop can answer:
 * that relevance order survives loadIds(), that a product carries a usable link
 * and image, and that a category says which one of several same-named ones it
 * is.
 */
class SuggestRenderTest extends TestCase
{
    private const SHOP_ID = 1;
    private const LANG_ID = 0;

    private ExposedSuggestController $controller;

    protected function setUp(): void
    {
        $engine = ContainerFactory::getInstance()->getContainer()->get(SearchEngineInterface::class);

        if (!$engine->isAvailable(self::SHOP_ID, self::LANG_ID)) {
            $this->markTestSkipped('No index for this scope - run foun10:easysearch:reindex first.');
        }

        $this->controller = new ExposedSuggestController();
    }

    /**
     * Product IDs the engine actually returns, so the test runs against what
     * the dropdown would really be handed.
     *
     * @return string[]
     */
    private function productIds(int $count): array
    {
        $engine = ContainerFactory::getInstance()->getContainer()->get(SearchEngineInterface::class);

        $rows = DatabaseHelper::fetchAll(
            'SELECT FOUN10TERMRAW AS term
             FROM ' . \foun10\EasySearch\Index\DictionaryBuilder::TABLE . '
             WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId AND FOUN10LENGTH >= 4
             ORDER BY FOUN10FREQUENCY DESC LIMIT 1',
            [':shopId' => self::SHOP_ID, ':langId' => self::LANG_ID]
        );

        if ($rows === []) {
            $this->markTestSkipped('The correction dictionary is empty - run a reindex.');
        }

        $result = $engine->search(new SearchQuery(
            (string) $rows[0]['term'],
            self::SHOP_ID,
            self::LANG_ID,
            [],
            SearchQuery::SORT_RELEVANCE,
            0,
            $count
        ));

        if (count($result->getProductIds()) < $count) {
            $this->markTestSkipped('Needs at least ' . $count . ' products in one result.');
        }

        return $result->getProductIds();
    }

    // ---------------------------------------------------------------
    // products
    // ---------------------------------------------------------------

    public function testAProductCarriesEverythingTheDropdownDraws(): void
    {
        $ids = $this->productIds(1);

        $products = $this->controller->renderProductsPublic($ids);

        $this->assertCount(1, $products);
        $this->assertSame(
            ['id', 'title', 'brand', 'url', 'image', 'price', 'oldPrice'],
            array_keys($products[0])
        );
        $this->assertSame($ids[0], $products[0]['id']);
    }

    /**
     * The title has to survive the variant problem too: a variant's own
     * OXTITLE is empty and the shop's Article model resolves the parent's, so
     * a dropdown row for a variant must still be named.
     */
    public function testAProductIsNamedEvenWhenTheHitIsAVariant(): void
    {
        foreach ($this->controller->renderProductsPublic($this->productIds(3)) as $product) {
            $this->assertNotSame('', $product['title'], 'A dropdown row without a name is unusable.');
        }
    }

    public function testAProductCarriesALinkAndAPicture(): void
    {
        $product = $this->controller->renderProductsPublic($this->productIds(1))[0];

        $this->assertStringStartsWith('http', $product['url']);
        $this->assertStringStartsWith('http', $product['image']);
    }

    /**
     * Prices come from the shop's own pricing, so a suggestion cannot promise
     * a figure the product page then does not honour.
     */
    public function testAProductCarriesAFormattedPrice(): void
    {
        $product = $this->controller->renderProductsPublic($this->productIds(1))[0];

        $this->assertMatchesRegularExpression(
            '/\d/',
            $product['price'],
            'A product with no price at all in the dropdown is a product nobody clicks.'
        );
    }

    /**
     * loadIds() does not preserve the order it was given, so the renderer
     * walks the engine's IDs rather than the loaded list - otherwise the
     * dropdown reorders itself away from relevance.
     */
    public function testRelevanceOrderSurvivesLoading(): void
    {
        $ids = $this->productIds(3);

        $rendered = array_column($this->controller->renderProductsPublic($ids), 'id');

        $this->assertSame($ids, $rendered);
    }

    public function testAnIdNothingIsBehindIsSkippedRatherThanRenderedEmpty(): void
    {
        $ids = $this->productIds(1);

        $products = $this->controller->renderProductsPublic(
            [...$ids, 'thisarticleiddoesnotexist000000']
        );

        $this->assertCount(1, $products);
    }

    public function testNoProductsMeansNoQueryAtAll(): void
    {
        $this->assertSame([], $this->controller->renderProductsPublic([]));
    }

    // ---------------------------------------------------------------
    // categories
    // ---------------------------------------------------------------

    public function testACategoryCarriesItsNameAndALink(): void
    {
        $categoryId = $this->aCategoryId();

        $categories = $this->controller->renderCategoriesPublic([$categoryId]);

        $this->assertCount(1, $categories);
        $this->assertSame($categoryId, $categories[0]['id']);
        $this->assertNotSame('', $categories[0]['title']);
        $this->assertStringStartsWith('http', $categories[0]['url']);
    }

    /**
     * The path is what makes a category suggestion usable: a name on its own is
     * ambiguous when it exists under more than one parent, so the customer has
     * to see which one they would land in.
     */
    public function testASubcategoryCarriesThePathThatLeadsToIt(): void
    {
        $categoryId = $this->aSubcategoryId();

        $category = $this->controller->renderCategoriesPublic([$categoryId])[0];

        $this->assertNotSame('', $category['path'], 'A subcategory without its path is ambiguous.');
        $this->assertStringNotContainsString(
            $category['title'],
            $category['path'],
            'The path lists the ancestors, not the category itself.'
        );
    }

    public function testATopLevelCategoryHasNoPathAboveIt(): void
    {
        $categoryId = $this->aTopLevelCategoryId();

        $this->assertSame('', $this->controller->renderCategoriesPublic([$categoryId])[0]['path']);
    }

    public function testACategoryThatIsNotThereIsSkipped(): void
    {
        $this->assertSame(
            [],
            $this->controller->renderCategoriesPublic(['thiscategoryiddoesnotexist00000'])
        );
    }

    private function aCategoryId(): string
    {
        return $this->oneCategory("WHERE OXACTIVE = 1");
    }

    private function aSubcategoryId(): string
    {
        return $this->oneCategory("WHERE OXACTIVE = 1 AND OXPARENTID != '' AND OXPARENTID != 'oxrootid'");
    }

    private function aTopLevelCategoryId(): string
    {
        return $this->oneCategory("WHERE OXACTIVE = 1 AND (OXPARENTID = '' OR OXPARENTID = 'oxrootid')");
    }

    private function oneCategory(string $where): string
    {
        $rows = DatabaseHelper::fetchAll(
            'SELECT OXID FROM oxcategories ' . $where . ' AND OXSHOPID = ' . self::SHOP_ID . ' LIMIT 1'
        );

        if ($rows === []) {
            $this->markTestSkipped('The catalogue holds no category matching: ' . $where);
        }

        return (string) $rows[0]['OXID'];
    }
}

/**
 * The controller with its two model-loading steps reachable.
 *
 * A subclass rather than reflection, so the methods are called exactly as the
 * endpoint calls them - including through whatever the shop's own extension
 * chain has put in between.
 */
class ExposedSuggestController extends SuggestController
{
    /**
     * @param string[] $productIds
     *
     * @return array<int, array<string, string>>
     */
    public function renderProductsPublic(array $productIds): array
    {
        return $this->renderProducts($productIds);
    }

    /**
     * @param string[] $categoryIds
     *
     * @return array<int, array<string, string>>
     */
    public function renderCategoriesPublic(array $categoryIds): array
    {
        return $this->renderCategories($categoryIds);
    }
}
