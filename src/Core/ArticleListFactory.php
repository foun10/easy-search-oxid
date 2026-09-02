<?php
declare(strict_types=1);

namespace foun10\EasySearch\Core;

use OxidEsales\Eshop\Application\Model\ArticleList;

/**
 * Turns the engine's product IDs into the article list the shop renders.
 *
 * Shared by the search page and the category page so the two cannot drift
 * apart on the two things that are easy to get wrong here.
 *
 * loadIds() applies the shop's own active snippet, so anything hidden from the
 * current context drops out at this point rather than having to be replicated
 * in the index. It returns rows in database order though, which would throw
 * away the engine's ranking entirely - hence the reordering afterwards.
 */
class ArticleListFactory
{
    /**
     * @param string[] $productIds In the order the engine ranked them
     */
    public function fromIds(array $productIds): ArticleList
    {
        $articleList = oxNew(ArticleList::class);

        if ($productIds === []) {
            return $articleList;
        }

        $articleList->loadIds($productIds);

        $sorted = [];

        foreach ($productIds as $productId) {
            // An ID the engine returned but loadIds() dropped is simply gone -
            // the index is a snapshot and the shop's own rules win.
            if (isset($articleList[$productId])) {
                $sorted[$productId] = $articleList[$productId];
            }
        }

        $articleList->assign($sorted);

        return $articleList;
    }
}
