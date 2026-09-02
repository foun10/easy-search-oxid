<?php
declare(strict_types=1);

namespace foun10\EasySearch\Index;

use foun10\EasySearch\Core\DatabaseHelper;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\TableViewNameGenerator;

/**
 * Applies article discounts to the indexed price.
 *
 * Straight port of foun10\Doofinder\Exports\Products::getDiscountedProductPrice().
 * A discount counts when it is assigned through oxobject2discount to the
 * article itself, to its parent, or to one of the product's categories, and
 * when it is active either permanently or within its date range. Percentage
 * and absolute discounts are applied to the price in turn; anything else
 * (notably 'itm' bundle discounts) is ignored because it does not change the
 * item price.
 *
 * Everything beyond that is basket territory and has no business here.
 *
 * The one structural change against the original is batching: discounts and
 * their assignments are loaded once per shop instead of twice per product.
 * The matching rules are unchanged, only the number of queries is.
 *
 * User group discounts are out of reach at index time - there is no customer -
 * so the indexed price is the one an anonymous visitor sees. That is the right
 * basis for sorting and range filtering; the price shown on the page still
 * comes from the shop's own price logic.
 */
class DiscountResolver
{
    protected const TYPE_ARTICLES = 'oxarticles';
    protected const TYPE_CATEGORIES = 'oxcategories';

    /**
     * @var array<int, array<int, array<string, mixed>>> Discounts per shop
     */
    protected array $discountCache = [];

    /**
     * Calculates the effective price for a batch of articles.
     *
     * @param array<int, array{articleId: string, parentId: string, categoryIds: string[], price: float}> $articles
     *
     * @return array<string, float> Effective price keyed by article ID
     */
    public function resolve(array $articles, int $shopId, int $langId): array
    {
        if ($articles === []) {
            return [];
        }

        $discounts = $this->getDiscounts($shopId, $langId);

        if ($discounts === []) {
            return [];
        }

        $prices = [];

        foreach ($articles as $article) {
            $prices[$article['articleId']] = $this->applyDiscounts($article, $discounts);
        }

        return $prices;
    }

    /**
     * @param array{articleId: string, parentId: string, categoryIds: string[], price: float} $article
     * @param array<int, array<string, mixed>>                                                $discounts
     */
    protected function applyDiscounts(array $article, array $discounts): float
    {
        $price = $article['price'];

        foreach ($discounts as $discount) {
            if (!$this->isApplicable($discount, $article)) {
                continue;
            }

            if ($discount['type'] === '%') {
                $price = $price * ((100 - (float) $discount['value']) / 100);

                continue;
            }

            if ($discount['type'] === 'abs') {
                $price = $price - (float) $discount['value'];
            }
        }

        return $price;
    }

    /**
     * @param array<string, mixed>                                                           $discount
     * @param array{articleId: string, parentId: string, categoryIds: string[], price: float} $article
     */
    protected function isApplicable(array $discount, array $article): bool
    {
        if (in_array($article['articleId'], $discount['articleIds'], true)) {
            return true;
        }

        // A variant inherits what is assigned to its parent.
        if ($article['parentId'] !== '' && in_array($article['parentId'], $discount['articleIds'], true)) {
            return true;
        }

        return array_intersect($article['categoryIds'], $discount['categoryIds']) !== [];
    }

    /**
     * Loads the shop's discounts together with their assignments and keeps
     * them for the rest of the run.
     *
     * Ordered by OXSORT so repeated runs produce the same price - with a
     * percentage and an absolute discount on one article the order decides the
     * result, and the original had no ORDER BY at all.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getDiscounts(int $shopId, int $langId): array
    {
        if (isset($this->discountCache[$shopId])) {
            return $this->discountCache[$shopId];
        }

        $discountView = Registry::get(TableViewNameGenerator::class)
            ->getViewName('oxdiscount', $langId, $shopId);
        $now = date('Y-m-d H:i:s', Registry::getUtilsDate()->getTime());

        $sql = "
            SELECT OXID, OXADDSUM, OXADDSUMTYPE
            FROM {$discountView}
            WHERE OXACTIVE = 1
                OR (OXACTIVE = 0 AND OXACTIVEFROM < :now AND OXACTIVETO > :now)
            ORDER BY OXSORT ASC, OXID ASC
        ";

        $rows = DatabaseHelper::fetchAll($sql, [':now' => $now]);

        if ($rows === []) {
            $this->discountCache[$shopId] = [];

            return [];
        }

        $assignments = $this->fetchAssignments(array_column($rows, 'OXID'));
        $discounts = [];

        foreach ($rows as $row) {
            $discountId = (string) $row['OXID'];
            $articleIds = $assignments[$discountId][self::TYPE_ARTICLES] ?? [];
            $categoryIds = $assignments[$discountId][self::TYPE_CATEGORIES] ?? [];

            // Unassigned discounts can never match, so they are dropped here
            // rather than tested against every article.
            if ($articleIds === [] && $categoryIds === []) {
                continue;
            }

            $discounts[] = [
                'id' => $discountId,
                'type' => (string) $row['OXADDSUMTYPE'],
                'value' => (float) $row['OXADDSUM'],
                'articleIds' => $articleIds,
                'categoryIds' => $categoryIds,
            ];
        }

        $this->discountCache[$shopId] = $discounts;

        return $discounts;
    }

    /**
     * @param string[] $discountIds
     *
     * @return array<string, array<string, string[]>>
     */
    protected function fetchAssignments(array $discountIds): array
    {
        $database = DatabaseProvider::getDb();
        $quotedIds = implode(', ', $database->quoteArray($discountIds));

        $sql = "
            SELECT OXDISCOUNTID, OXOBJECTID, OXTYPE
            FROM oxobject2discount
            WHERE OXDISCOUNTID IN ({$quotedIds})
                AND OXTYPE IN ('" . self::TYPE_ARTICLES . "', '" . self::TYPE_CATEGORIES . "')
        ";

        $assignments = [];

        foreach (DatabaseHelper::fetchAll($sql) as $row) {
            $assignments[(string) $row['OXDISCOUNTID']][(string) $row['OXTYPE']][] =
                (string) $row['OXOBJECTID'];
        }

        return $assignments;
    }
}
