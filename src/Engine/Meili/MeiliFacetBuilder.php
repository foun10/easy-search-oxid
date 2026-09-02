<?php
declare(strict_types=1);

namespace foun10\EasySearch\Engine\Meili;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\ColorValue;
use foun10\EasySearch\Core\FacetAssembler;
use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Engine\Query\SearchQuery;
use foun10\EasySearch\Engine\Result\Facet;
use foun10\EasySearch\Meili\IndexSchema;
use foun10\EasySearch\Meili\MeiliClient;
use foun10\EasySearch\Meili\MeiliConfiguration;
use foun10\EasySearch\Meili\MeiliException;

/**
 * Counts the filter sidebar out of Meilisearch facet distributions.
 *
 * Same rule as the SQL side: a facet is counted with its own selection removed,
 * or picking "red" would show every other colour at zero. Meilisearch has no
 * per-facet exclusion either, so the query pattern ends up identical - one
 * search per selected facet, one shared search for all the rest.
 *
 * These counting searches deliberately do **not** pass `distinct`, and they are
 * therefore separate from the query that fetched the hits even when nothing is
 * selected and the conditions are the same. Riding along on the result query
 * would be one request cheaper and quietly wrong: with `distinct` in play
 * Meilisearch counts the *deduplicated* documents, so a bra that comes in cups
 * B to E would count towards whichever cup its representative variant happens
 * to have and towards no other. The sidebar would then invite the customer to
 * filter for a cup the product does have and show it as unavailable.
 *
 * What is left is still not the SQL engine's number:
 *
 *  - Counts are documents, so they count variants where the SQL engine counts
 *    DISTINCT groups per value. A bra with three C-cup variants contributes
 *    three. Meilisearch cannot express "distinct groupId per facet value" -
 *    getting that exactly right would mean a second, group-level index.
 *  - Ordering ties are resolved by PHP's byte comparison rather than the
 *    database collation, so two values with the same count can swap places.
 */
class MeiliFacetBuilder
{
    public function __construct(
        protected MeiliClient $client,
        protected MeiliConfiguration $configuration,
        protected FilterBuilder $filterBuilder,
        protected AttributeConfiguration $attributeConfiguration,
        protected FacetAssembler $facetAssembler
    ) {
    }

    /**
     * @return Facet[]
     */
    public function build(SearchQuery $query): array
    {
        $attributeIds = array_map(
            'strval',
            $this->attributeConfiguration->getFacetAttributeIds($query->getShopId())
        );

        if ($attributeIds === []) {
            return [];
        }

        $selection = $this->facetAssembler->getSelectionMap($query);
        $distribution = $this->fetchDistribution($query, $attributeIds, $selection);
        $counts = [];

        foreach ($attributeIds as $attributeId) {
            $counts[$attributeId] = $this->toCounts(
                (array) ($distribution[IndexSchema::facetField($attributeId)] ?? [])
            );
        }

        return $this->facetAssembler->assemble(
            $query,
            $attributeIds,
            $counts,
            fn (string $attributeId, string $valueId, string $mode): string
                => $this->fetchValueLabel($query, $attributeId, $valueId, $mode)
        );
    }

    /**
     * The facet fields of a shop, so a caller that runs its own search can ask
     * for the distribution in the same request.
     *
     * @return string[]
     */
    public function getFacetFields(int $shopId): array
    {
        return array_map(
            static fn ($attributeId): string => IndexSchema::facetField((string) $attributeId),
            $this->attributeConfiguration->getFacetAttributeIds($shopId)
        );
    }

    /**
     * @param string[]                $attributeIds
     * @param array<string, string[]> $selection
     *
     * @return array<string, array<string, int>>
     */
    protected function fetchDistribution(SearchQuery $query, array $attributeIds, array $selection): array
    {
        $selected = [];
        $unselected = [];

        foreach ($attributeIds as $attributeId) {
            if (($selection[$attributeId] ?? []) === []) {
                $unselected[] = $attributeId;

                continue;
            }

            $selected[] = $attributeId;
        }

        $distribution = [];

        if ($unselected !== []) {
            $distribution = $this->search($query, $unselected, null);
        }

        foreach ($selected as $attributeId) {
            $distribution += $this->search($query, [$attributeId], $attributeId);
        }

        return $distribution;
    }

    /**
     * One counting search: no hits, only the distribution.
     *
     * @param string[] $attributeIds
     *
     * @return array<string, array<string, int>>
     */
    protected function search(SearchQuery $query, array $attributeIds, ?string $excludeAttributeId): array
    {
        $facets = array_map(
            static fn (string $attributeId): string => IndexSchema::facetField($attributeId),
            $attributeIds
        );

        try {
            $response = $this->client->post(
                '/indexes/' . $this->configuration->getIndexUid($query->getShopId(), $query->getLangId()) . '/search',
                [
                    'q' => $query->getTerm(),
                    'filter' => $this->filterBuilder->build($query, $excludeAttributeId),
                    'limit' => 0,
                    'facets' => $facets,
                ]
            );
        } catch (MeiliException $exception) {
            return [];
        }

        return (array) ($response['facetDistribution'] ?? []);
    }

    /**
     * Distribution entries in the order the sidebar renders them: most hits
     * first, ties alphabetical - the ordering the SQL engine gets from its
     * ORDER BY.
     *
     * @param array<string, int> $distribution Encoded facet value => count
     *
     * @return array<int, array{valueId: string, value: string, count: int}>
     */
    protected function toCounts(array $distribution): array
    {
        $counts = [];

        foreach ($distribution as $encoded => $count) {
            $decoded = IndexSchema::decodeFacetValue((string) $encoded);

            $counts[] = [
                'valueId' => $decoded['valueId'],
                'value' => $decoded['label'],
                'count' => (int) $count,
            ];
        }

        usort(
            $counts,
            static fn (array $left, array $right): int
                => [$right['count'], $left['value']] <=> [$left['count'], $right['value']]
        );

        return $counts;
    }

    /**
     * Label of a selected value that is no longer in the result set.
     *
     * Asked of the index rather than kept in the URL, so filter links stay
     * short: one search narrowed to that value, whose distribution carries the
     * label the value was stored with.
     */
    protected function fetchValueLabel(
        SearchQuery $query,
        string $attributeId,
        string $valueId,
        string $mode = FacetDisplay::MODE_DEFAULT
    ): string {
        $field = IndexSchema::facetField($attributeId);

        try {
            $response = $this->client->post(
                '/indexes/' . $this->configuration->getIndexUid($query->getShopId(), $query->getLangId()) . '/search',
                [
                    'q' => '',
                    'filter' => [IndexSchema::filterField($attributeId) . ' = ' . $this->filterBuilder->quote($valueId)],
                    'limit' => 0,
                    'facets' => [$field],
                ]
            );
        } catch (MeiliException $exception) {
            return $valueId;
        }

        foreach (array_keys((array) ($response['facetDistribution'][$field] ?? [])) as $encoded) {
            $decoded = IndexSchema::decodeFacetValue((string) $encoded);

            if ($decoded['valueId'] !== $valueId) {
                continue;
            }

            return FacetDisplay::isColor($mode)
                ? ColorValue::parse($decoded['label'])->getName()
                : $decoded['label'];
        }

        return $valueId;
    }
}
