<?php
declare(strict_types=1);

namespace foun10\EasySearch\Meili;

use foun10\EasySearch\Index\IndexDocument;
use foun10\EasySearch\Synonym\SynonymRule;

/**
 * How an IndexDocument looks as a Meilisearch document, and how the index has
 * to be configured to answer the same questions the MySql engine answers.
 *
 * The interesting part is the facet fields. A document store has no link table,
 * so a variant's attributes are flattened onto the document itself - one field
 * per attribute:
 *
 *   f_<attributeId>   the value IDs, what a filter matches on
 *   fl_<attributeId>  "<valueId><US><label>", what the facet counts come from
 *
 * Two fields rather than one because the two jobs disagree. A filter arrives as
 * a value ID out of the URL and must match exactly. A facet count has to come
 * back with something to render, and Meilisearch returns facet distributions
 * keyed by the stored value - so the label has to be inside that value or the
 * sidebar would need a second lookup per value to find out what "a3f2..." is
 * called. Carrying both costs one extra field per attribute and saves a query
 * per facet.
 */
class IndexSchema
{
    public const PRIMARY_KEY = 'id';

    public const FIELD_FILTER_PREFIX = 'f_';
    public const FIELD_FACET_PREFIX = 'fl_';

    /**
     * ASCII unit separator between value ID and label. Not a character that can
     * occur in an attribute value out of the ERP, which "|" or ":" both can.
     */
    public const FACET_SEPARATOR = "\x1F";

    /**
     * Meilisearch answers at most this many hits per query, ranking included.
     * The default of 1000 is below what a category listing needs to paginate
     * through, and every raise costs ranking time on the deep pages nobody
     * visits - 20k is the whole catalogue of a subshop without being unbounded.
     */
    protected const MAX_TOTAL_HITS = 20000;

    /**
     * Facet values Meilisearch returns per facet. Has to stay above the number
     * of distinct values an attribute can have (sizes, colours: dozens) and
     * above the category count, which the rebuild guard counts through a facet
     * distribution.
     */
    protected const MAX_VALUES_PER_FACET = 2000;

    public static function filterField(string $attributeId): string
    {
        return self::FIELD_FILTER_PREFIX . $attributeId;
    }

    public static function facetField(string $attributeId): string
    {
        return self::FIELD_FACET_PREFIX . $attributeId;
    }

    public static function attributeIdFromFacetField(string $field): string
    {
        return str_starts_with($field, self::FIELD_FACET_PREFIX)
            ? substr($field, strlen(self::FIELD_FACET_PREFIX))
            : $field;
    }

    public static function encodeFacetValue(string $valueId, string $label): string
    {
        return $valueId . self::FACET_SEPARATOR . $label;
    }

    /**
     * @return array{valueId: string, label: string}
     */
    public static function decodeFacetValue(string $encoded): array
    {
        $parts = explode(self::FACET_SEPARATOR, $encoded, 2);

        return [
            'valueId' => $parts[0],
            'label' => trim($parts[1] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(IndexDocument $document): array
    {
        $payload = [
            self::PRIMARY_KEY => $document->getId(),
            'shopId' => $document->getShopId(),
            'langId' => $document->getLangId(),
            'articleId' => $document->getArticleId(),
            'parentId' => $document->getParentId(),
            'groupId' => $document->getGroupId(),
            'title' => $document->getTitle(),
            'artNum' => $document->getArtNum(),
            'ean' => $document->getEan(),
            'mpn' => $document->getMpn(),
            'brand' => $document->getBrand(),
            'manufacturerId' => $document->getManufacturerId(),
            'categoryPaths' => $document->getCategoryPaths(),
            'categoryIds' => $document->getCategoryIds(),
            'boostText' => $document->getBoostText(),
            'searchText' => $document->getSearchText(),
            'price' => $document->getPrice(),
            'stock' => $document->getStock(),
            'soldAmount' => $document->getSoldAmount(),
            // A date string sorts as a string; the listing sorts "newest
            // first" numerically and needs no timezone discussion.
            'insertTimestamp' => $this->toTimestamp($document->getInsertDate()),
            'visible' => $document->isVisible(),
        ];

        foreach ($document->getAttributes() as $attribute) {
            $attributeId = (string) $attribute['attributeId'];
            $valueId = (string) $attribute['valueId'];
            $value = trim((string) $attribute['value']);

            $payload[self::filterField($attributeId)][] = $valueId;
            $payload[self::facetField($attributeId)][] = self::encodeFacetValue($valueId, $value);
        }

        return $payload;
    }

    /**
     * The index settings that make Meilisearch behave like the MySql engine.
     *
     * Searchable attributes are ordered, and Meilisearch ranks an earlier
     * attribute above a later one - which is the same job the MySql engine does
     * with a separately weighted boost column. boostText holds title, brand and
     * the identifiers, searchText holds everything including the description,
     * so an exact title hit outranks a mention buried in the long text without
     * any weighting arithmetic.
     *
     * soldAmount is appended to the ranking rules as the last tie break, the
     * same one the MySql engine falls back to when nothing scores.
     *
     * @param string[]      $facetAttributeIds
     * @param SynonymRule[] $synonymRules
     *
     * @return array<string, mixed>
     */
    public function buildSettings(array $facetAttributeIds, array $synonymRules = []): array
    {
        $filterable = ['visible', 'manufacturerId', 'price', 'categoryIds', 'groupId'];

        foreach ($facetAttributeIds as $attributeId) {
            $filterable[] = self::filterField((string) $attributeId);
            $filterable[] = self::facetField((string) $attributeId);
        }

        return [
            'searchableAttributes' => ['boostText', 'searchText'],
            'filterableAttributes' => $filterable,
            'sortableAttributes' => ['price', 'title', 'insertTimestamp', 'soldAmount'],
            'displayedAttributes' => ['id', 'groupId', 'articleId', 'title', 'price', 'manufacturerId'],
            'rankingRules' => [
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
                'soldAmount:desc',
            ],
            // The module's own synonym rules, so both engines broaden a query
            // the same way. Meilisearch expands at query time as well, so a
            // saved rule takes effect without a reindex here too - it only has
            // to be pushed into the settings.
            'synonyms' => $this->buildSynonyms($synonymRules),
            'faceting' => ['maxValuesPerFacet' => self::MAX_VALUES_PER_FACET],
            'pagination' => ['maxTotalHits' => self::MAX_TOTAL_HITS],
        ];
    }

    /**
     * Meilisearch stores synonyms as "term => replacements". A two way rule is
     * two entries, because Meilisearch's own mapping is one way.
     *
     * @param SynonymRule[] $rules
     *
     * @return array<string, string[]>
     */
    public function buildSynonyms(array $rules): array
    {
        $synonyms = [];

        foreach ($rules as $rule) {
            if (!$rule->isActive() || !$rule->isComplete()) {
                continue;
            }

            $term = mb_strtolower(trim($rule->getTerm()));
            $replacements = array_values(array_filter(array_map(
                static fn (string $synonym): string => mb_strtolower(trim($synonym)),
                $rule->getSynonymList()
            )));

            if ($term === '' || $replacements === []) {
                continue;
            }

            $synonyms[$term] = array_values(array_unique(
                array_merge($synonyms[$term] ?? [], $replacements)
            ));

            if (!$rule->isTwoWay()) {
                continue;
            }

            foreach ($replacements as $replacement) {
                $others = array_values(array_diff(array_merge([$term], $replacements), [$replacement]));

                $synonyms[$replacement] = array_values(array_unique(
                    array_merge($synonyms[$replacement] ?? [], $others)
                ));
            }
        }

        return $synonyms;
    }

    protected function toTimestamp(?string $date): ?int
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? null : $timestamp;
    }
}
