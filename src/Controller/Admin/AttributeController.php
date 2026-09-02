<?php
declare(strict_types=1);

namespace foun10\EasySearch\Controller\Admin;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Core\ShopLanguages;
use OxidEsales\Eshop\Application\Controller\Admin\AdminController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use Throwable;

/**
 * foun10 -> Suche -> Attribute
 *
 * Assigns attributes to the filter sidebar and to the searchable text, and
 * fixes the order the filters appear in.
 *
 * Configuration is per subshop: each shop gets its own arrangement, which is
 * the whole reason this could not stay a shared module setting.
 */
class AttributeController extends AdminController
{
    use ReindexPhases;

    protected $_sThisTemplate = '@foun10EasySearch/admin/attributes';

    /**
     * @var array<string, string[]>|null
     */
    protected ?array $samples = null;

    /**
     * @var array<string, string>|null
     */
    protected ?array $allAttributes = null;

    /**
     * Characters kept per example value.
     */
    protected const SAMPLE_VALUE_LENGTH = 32;

    /**
     * Saves the arrangement the screen submits.
     *
     * Three fields arrive: the order of the configured list, and the IDs ticked
     * as filter and as searchable. The two roles are independent - an attribute
     * is very often both - so they are read as separate sets over one ordered
     * list rather than as membership of separate lists.
     */
    public function save(): void
    {
        $request = $this->getRequest();

        $order = $this->toIdList($request->getRequestEscapedParameter('order'));
        $facetIds = $this->toIdList($request->getRequestEscapedParameter('facets'));
        $searchableIds = $this->toIdList($request->getRequestEscapedParameter('searchable'));

        // Free text and a select, so these arrive as real form fields rather
        // than through the comma separated lists the drag and drop writes.
        $displays = $this->toMap($request->getRequestEscapedParameter('display'));
        $titles = $this->readTitles();

        $entries = [];

        foreach ($order as $attributeId) {
            $entries[] = [
                'attributeId' => $attributeId,
                'facet' => in_array($attributeId, $facetIds, true),
                'searchable' => in_array($attributeId, $searchableIds, true),
                'display' => $displays[$attributeId] ?? FacetDisplay::MODE_DEFAULT,
            ];
        }

        $configuration = $this->getConfiguration();
        $shopId = $this->getEditShopId();

        $configuration->save($shopId, $entries);

        // Labels are keyed by attribute, so only those still configured survive
        // - dragging an attribute out drops its label with it.
        $configuration->saveTitles(
            $shopId,
            array_intersect_key($titles, array_flip($order))
        );
    }

    /**
     * Submitted labels as attribute ID => language ID => label.
     *
     * OXID escapes request parameters into HTML entities on the way in, so an
     * apostrophe would be stored as &#039; and shown back that way next time.
     *
     * @return array<string, array<int, string>>
     */
    protected function readTitles(): array
    {
        $submitted = $this->getRequest()->getRequestEscapedParameter('title');

        if (!is_array($submitted)) {
            return [];
        }

        $titles = [];

        foreach ($submitted as $attributeId => $perLanguage) {
            $attributeId = (string) $attributeId;

            if (!is_array($perLanguage) || !$this->isAttributeId($attributeId)) {
                continue;
            }

            foreach ($perLanguage as $langId => $title) {
                $titles[$attributeId][(int) $langId] = trim(
                    html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return $titles;
    }

    /**
     * @return array<string, string>
     */
    protected function toMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            $key = (string) $key;

            if ($this->isAttributeId($key)) {
                $map[$key] = (string) $entry;
            }
        }

        return $map;
    }

    protected function isAttributeId(string $value): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $value);
    }

    /**
     * Languages of the shop being edited, so a label can be given in each.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function getLanguages(): array
    {
        $languages = [];

        foreach ($this->getService(ShopLanguages::class)->getActive($this->getEditShopId()) as $language) {
            $languages[] = [
                'id' => $language['id'],
                'label' => strtoupper($language['abbr']),
            ];
        }

        return $languages;
    }

    /**
     * The rendering modes a facet can be set to.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getDisplayModes(): array
    {
        $modes = [];

        foreach (FacetDisplay::MODES as $mode) {
            $modes[] = [
                'value' => $mode,
                'label' => $this->translate(FacetDisplay::getLabelIdent($mode)),
            ];
        }

        return $modes;
    }

    /**
     * The configured attributes in their arranged order, each carrying both
     * role flags so the screen can render two checkboxes per row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfiguredAttributes(): array
    {
        $titles = $this->getAllAttributes();
        $configured = [];

        foreach ($this->getConfiguration()->getRows($this->getEditShopId()) as $row) {
            $attributeId = (string) $row['FOUN10ATTRID'];

            $configured[] = [
                'id' => $attributeId,
                // An attribute deleted from the catalogue keeps its ID as the
                // label, so it can still be dragged out of the list.
                'title' => $titles[$attributeId] ?? $attributeId,
                'facet' => (int) $row['FOUN10FACET'] === 1,
                'searchable' => (int) $row['FOUN10EASYSEARCHABLE'] === 1,
                'display' => FacetDisplay::normalize(
                    isset($row['FOUN10DISPLAY']) ? (string) $row['FOUN10DISPLAY'] : null
                ),
                'labels' => $this->getCustomTitles($attributeId),
                'sample' => $this->getSample($attributeId),
            ];
        }

        return $configured;
    }

    /**
     * The labels a merchant entered for one attribute, keyed by language ID.
     *
     * Empty strings for languages nobody filled in, so the template can render
     * one input per language without checking.
     *
     * @return array<int, string>
     */
    public function getCustomTitles(string $attributeId): array
    {
        $labels = [];

        foreach ($this->getLanguages() as $language) {
            $langId = (int) $language['id'];
            $titles = $this->getConfiguration()->getCustomTitles($this->getEditShopId(), $langId);
            $labels[$langId] = $titles[$attributeId] ?? '';
        }

        return $labels;
    }

    /**
     * Attributes carrying neither role.
     *
     * @return array<int, array<string, string>>
     */
    public function getUnusedAttributes(): array
    {
        $assigned = array_column($this->getConfiguredAttributes(), 'id');

        $unused = [];

        foreach ($this->getAllAttributes() as $attributeId => $title) {
            if (in_array($attributeId, $assigned, true)) {
                continue;
            }

            $unused[] = [
                'id' => $attributeId,
                'title' => $title,
                'sample' => $this->getSample($attributeId),
            ];
        }

        return $unused;
    }

    /**
     * Example values for one attribute, as a single readable line.
     *
     * Answers the question the screen exists to answer - is this attribute
     * worth offering as a filter - without making anyone leave for the
     * attribute administration.
     */
    public function getSample(string $attributeId): string
    {
        $values = $this->getSamples()[$attributeId] ?? [];

        // Some attributes hold whole paragraphs - ingredient lists, care
        // instructions. Shortening each value here keeps the markup small and
        // the line scannable; the point is to recognise the kind of value, not
        // to read it.
        $values = array_map(
            static fn (string $value): string => mb_strlen($value) > self::SAMPLE_VALUE_LENGTH
                ? mb_substr($value, 0, self::SAMPLE_VALUE_LENGTH) . '…'
                : $value,
            $values
        );

        return implode(' · ', $values);
    }

    /**
     * Samples for every attribute at once. Loaded lazily so the query only runs
     * on a screen that actually renders them.
     *
     * @return array<string, string[]>
     */
    protected function getSamples(): array
    {
        if ($this->samples === null) {
            $this->samples = $this->getConfiguration()->getValueSamples(
                array_keys($this->getAllAttributes()),
                $this->getEditShopId(),
                $this->getTemplateLanguageId()
            );
        }

        return $this->samples;
    }

    /**
     * The shop the admin is currently editing, which is not necessarily shop 1
     * in a multishop setup.
     */
    public function getEditShopId(): int
    {
        return $this->getCurrentShopId();
    }

    /**
     * @return array<string, string>
     */
    protected function getAllAttributes(): array
    {
        if ($this->allAttributes === null) {
            $this->allAttributes = $this->getConfiguration()->getAvailableAttributes(
                $this->getEditShopId(),
                $this->getTemplateLanguageId()
            );
        }

        return $this->allAttributes;
    }

    /**
     * Each field arrives as one comma separated list, written by the drag and
     * drop script.
     *
     * @return string[]
     */
    protected function toIdList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $ids = array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $id): bool => $this->isAttributeId($id)
        );

        return array_values(array_unique($ids));
    }

    protected function getConfiguration(): AttributeConfiguration
    {
        /** @var AttributeConfiguration $configuration */
        $configuration = $this->getService(AttributeConfiguration::class);

        return $configuration;
    }

    /*
     * The shop touch points. getRequest() and getService() come from the
     * ReindexPhases trait, which this screen already uses for its rebuild
     * button; the two below are its own.
     */

    protected function getCurrentShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    /**
     * The language the backend is being displayed in, which is the one an
     * attribute's title and its example values should be read in.
     */
    protected function getTemplateLanguageId(): int
    {
        return (int) Registry::getLang()->getTplLanguage();
    }

    protected function translate(string $key): string
    {
        return (string) Registry::getLang()->translateString($key);
    }
}
