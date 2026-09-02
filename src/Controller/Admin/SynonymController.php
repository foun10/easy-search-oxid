<?php
declare(strict_types=1);

namespace foun10\EasySearch\Controller\Admin;

use foun10\EasySearch\Core\RequestValues;
use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Core\SynonymConfiguration;
use foun10\EasySearch\Synonym\SynonymRule;
use OxidEsales\Eshop\Application\Controller\Admin\AdminController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;

/**
 * foun10 -> Suche -> Synonyme
 *
 * A list of rules per subshop AND per language. Language matters even though
 * only German exists here today: word equivalences do not survive translation,
 * so the screen edits one language at a time rather than pretending the rules
 * are global.
 *
 * No reindex is needed after saving. Synonyms are applied when a query is
 * built, not written into the index, so a rule takes effect on the next search.
 */
class SynonymController extends AdminController
{
    use RequestValues;

    protected $_sThisTemplate = '@foun10EasySearch/admin/synonyms';

    /**
     * Rows offered on a screen that has none yet, so a merchant can start
     * typing instead of having to find an "add" button first.
     */
    protected const BLANK_ROWS = 3;

    /**
     * Where save() leaves the count for the request that renders the list.
     */
    protected const SESSION_SAVED = 'foun10EasySearchSynonymSaved';

    /**
     * Saves the rules of the language currently shown.
     *
     * Only that language is touched: the screen submits one language's list and
     * knows nothing about the others, so replacing more than that scope would
     * throw away rules that are not on screen.
     */
    public function save(): void
    {
        $langId = $this->getEditLanguageId();

        $stored = $this->getConfiguration()->save(
            $this->getEditShopId(),
            $langId,
            $this->readSubmittedRules()
        );

        $this->setSessionVariable(self::SESSION_SAVED, $stored);
    }

    /**
     * The submitted rows, in the order the form posted them - which is the
     * order they appear on screen, since the browser serialises fields in
     * document order.
     *
     * @return array<int, array{type: string, term: string, synonyms: string, active: bool}>
     */
    protected function readSubmittedRules(): array
    {
        $submitted = $this->getRequest()->getRequestEscapedParameter('rules');

        if (!is_array($submitted)) {
            return [];
        }

        $rules = [];

        foreach ($submitted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rules[] = [
                'type' => $this->readField($row, 'type'),
                'term' => $this->readField($row, 'term'),
                'synonyms' => $this->readField($row, 'synonyms'),
                // An unchecked checkbox posts nothing at all, so the row
                // carries a hidden companion field the script keeps in sync.
                'active' => (string) ($row['active'] ?? '0') === '1',
            ];
        }

        return $rules;
    }

    /**
     * OXID escapes request parameters into HTML entities on the way in. Left
     * alone, an apostrophe would be stored as &#039; and shown back that way on
     * the next visit, so it is decoded before it reaches the table.
     *
     * @param array<string, mixed> $row
     */
    protected function readField(array $row, string $field): string
    {
        $value = (string) ($row[$field] ?? '');

        return trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * The rules to render, always with a few empty rows at the end so there is
     * somewhere to type.
     *
     * A term handed over from the report screen becomes the first of those
     * empty rows, filled in and waiting for its synonyms - the merchant came
     * here to write a rule for exactly that word, and typing it a second time
     * is a step nobody needs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRules(): array
    {
        $rules = [];

        $configured = $this->getConfiguration()->getRules(
            $this->getEditShopId(),
            $this->getEditLanguageId()
        );

        foreach ($configured as $rule) {
            $rules[] = [
                'type' => $rule->getType(),
                'term' => $rule->getTerm(),
                'synonyms' => $rule->getSynonyms(),
                'active' => $rule->isActive(),
            ];
        }

        $blanks = self::BLANK_ROWS;
        $handedOver = $this->getHandedOverTerm();

        if ($handedOver !== '' && !$this->hasRuleFor($rules, $handedOver)) {
            $rules[] = ['type' => SynonymRule::TYPE_BOTH, 'term' => $handedOver, 'synonyms' => '', 'active' => true];
            $blanks--;
        }

        for ($index = 0; $index < $blanks; $index++) {
            $rules[] = ['type' => SynonymRule::TYPE_BOTH, 'term' => '', 'synonyms' => '', 'active' => true];
        }

        return $rules;
    }

    /**
     * The term the report screen sent over, if this is where it sent it.
     *
     * Cut to the length of the column it would be stored in, and left exactly
     * as typed otherwise - it is rendered as a form value and escaped there.
     */
    public function getHandedOverTerm(): string
    {
        $requested = $this->getRequest()->getRequestEscapedParameter('synonymTerm');

        if (!is_string($requested) || $requested === '') {
            return '';
        }

        return mb_substr(trim(html_entity_decode($requested, ENT_QUOTES, 'UTF-8')), 0, 255);
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    protected function hasRuleFor(array $rules, string $term): bool
    {
        foreach ($rules as $rule) {
            if (mb_strtolower((string) $rule['term']) === mb_strtolower($term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getTypes(): array
    {
        return [
            [
                'value' => SynonymRule::TYPE_BOTH,
                'label' => $this->translate('FOUN10_EASYSEARCH_SYNONYM_TYPE_BOTH'),
            ],
            [
                'value' => SynonymRule::TYPE_ONEWAY,
                'label' => $this->translate('FOUN10_EASYSEARCH_SYNONYM_TYPE_ONEWAY'),
            ],
        ];
    }

    /**
     * Languages of the shop being edited, so the screen can offer a switch.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function getLanguages(): array
    {
        /** @var ShopLanguages $shopLanguages */
        $shopLanguages = $this->getService(ShopLanguages::class);

        $languages = [];

        foreach ($shopLanguages->getActive($this->getEditShopId()) as $language) {
            $languages[] = [
                'id' => $language['id'],
                'label' => $language['name'],
            ];
        }

        return $languages;
    }

    /**
     * The language the screen is editing.
     *
     * Carried in its own parameter rather than the admin's own language switch:
     * that one decides which language the backend is displayed in, which is a
     * different question from which language's synonyms are being edited.
     */
    public function getEditLanguageId(): int
    {
        return max(0, $this->toInt($this->getRequest()->getRequestEscapedParameter('synonymLang')));
    }

    public function getEditShopId(): int
    {
        return $this->getCurrentShopId();
    }

    /**
     * How many rules the last save stored, once, so the screen can confirm it.
     *
     * Read through the session because save() redirects into a fresh request -
     * the count would otherwise be gone by the time the list is rendered. Read
     * once and cleared: a confirmation that survived into the next visit would
     * claim a save that did not happen.
     */
    public function getSavedCount(): ?int
    {
        $saved = $this->getSessionVariable(self::SESSION_SAVED);

        if ($saved === null) {
            return null;
        }

        $this->deleteSessionVariable(self::SESSION_SAVED);

        return (int) $saved;
    }

    protected function getConfiguration(): SynonymConfiguration
    {
        /** @var SynonymConfiguration $configuration */
        $configuration = $this->getService(SynonymConfiguration::class);

        return $configuration;
    }

    /*
     * The shop touch points, kept apart from the rules above.
     *
     * Each hands back a scalar, a request or a container entry rather than a
     * Config, Language or Session object, so what the screen decides - which
     * language it edits, what a submitted row means, when a confirmation is
     * shown - can be proven without a shop.
     */

    /**
     * @return \OxidEsales\Eshop\Core\Request
     */
    protected function getRequest()
    {
        return Registry::getRequest();
    }

    protected function getCurrentShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    protected function translate(string $key): string
    {
        return (string) Registry::getLang()->translateString($key);
    }

    protected function setSessionVariable(string $name, mixed $value): void
    {
        Registry::getSession()->setVariable($name, $value);
    }

    protected function getSessionVariable(string $name): mixed
    {
        return Registry::getSession()->getVariable($name);
    }

    protected function deleteSessionVariable(string $name): void
    {
        Registry::getSession()->deleteVariable($name);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function getService(string $id): object
    {
        return ContainerFactory::getInstance()->getContainer()->get($id);
    }
}
