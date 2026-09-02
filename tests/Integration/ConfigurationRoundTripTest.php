<?php

declare(strict_types=1);

namespace foun10\EasySearch\Tests\Integration;

use foun10\EasySearch\Core\AttributeConfiguration;
use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Core\FacetDisplay;
use foun10\EasySearch\Core\SynonymConfiguration;
use foun10\EasySearch\Synonym\SynonymRule;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use PHPUnit\Framework\TestCase;

/**
 * What the two admin screens store, read back out of the database.
 *
 * The unit tests prove the screens turn a form into the right entries; these
 * prove the entries survive a round trip through MySQL - which is a different
 * question, and the one where a column too short, a missing default or a
 * primary key that collides shows up.
 *
 * Written against a shop id no installation uses, so nothing here can touch a
 * real configuration, and cleared again afterwards. Both tables are keyed by
 * OXSHOPID, which is what makes that isolation actually hold.
 */
class ConfigurationRoundTripTest extends TestCase
{
    /**
     * A shop that does not exist. Both tables are plain rows keyed by shop, so
     * writing here is invisible to the running one.
     */
    private const SCRATCH_SHOP_ID = 990;

    private const LANG_ID = 0;

    private AttributeConfiguration $attributes;

    private SynonymConfiguration $synonyms;

    protected function setUp(): void
    {
        $container = ContainerFactory::getInstance()->getContainer();

        $this->attributes = $container->get(AttributeConfiguration::class);
        $this->synonyms = $container->get(SynonymConfiguration::class);

        $this->removeScratchRows();
    }

    protected function tearDown(): void
    {
        $this->removeScratchRows();
    }

    private function removeScratchRows(): void
    {
        $database = DatabaseProvider::getDb();

        foreach (['foun10easysearchattribute', 'foun10easysearchattributetitle', 'foun10easysearchsynonym'] as $table) {
            $database->execute(
                'DELETE FROM ' . $table . ' WHERE OXSHOPID = :shopId',
                [':shopId' => self::SCRATCH_SHOP_ID]
            );
        }
    }

    // ---------------------------------------------------------------
    // the attribute arrangement
    // ---------------------------------------------------------------

    public function testAnArrangementSurvivesTheRoundTrip(): void
    {
        $this->attributes->save(self::SCRATCH_SHOP_ID, [
            ['attributeId' => 'attr-a', 'facet' => true, 'searchable' => false, 'display' => FacetDisplay::MODE_COLOR],
            ['attributeId' => 'attr-b', 'facet' => false, 'searchable' => true, 'display' => FacetDisplay::MODE_DEFAULT],
        ]);

        $rows = $this->attributes->getRows(self::SCRATCH_SHOP_ID);

        $this->assertCount(2, $rows);
        $this->assertSame('attr-a', $rows[0]['FOUN10ATTRID']);
        $this->assertSame(1, (int) $rows[0]['FOUN10FACET']);
        $this->assertSame(0, (int) $rows[0]['FOUN10EASYSEARCHABLE']);
        $this->assertSame(FacetDisplay::MODE_COLOR, $rows[0]['FOUN10DISPLAY']);
    }

    /**
     * The order the merchant dragged them into is the order the sidebar shows,
     * so it has to come back out of the table the same way.
     */
    public function testTheArrangedOrderComesBackInTheSameOrder(): void
    {
        $this->attributes->save(self::SCRATCH_SHOP_ID, [
            ['attributeId' => 'attr-c', 'facet' => true, 'searchable' => false],
            ['attributeId' => 'attr-a', 'facet' => true, 'searchable' => false],
            ['attributeId' => 'attr-b', 'facet' => true, 'searchable' => false],
        ]);

        $this->assertSame(
            ['attr-c', 'attr-a', 'attr-b'],
            array_column($this->attributes->getRows(self::SCRATCH_SHOP_ID), 'FOUN10ATTRID')
        );
    }

    public function testTheTwoRolesAreStoredIndependently(): void
    {
        $this->attributes->save(self::SCRATCH_SHOP_ID, [
            ['attributeId' => 'attr-a', 'facet' => true, 'searchable' => true],
        ]);

        $this->assertSame(['attr-a'], $this->attributes->getFacetAttributeIds(self::SCRATCH_SHOP_ID));
        $this->assertSame(['attr-a'], $this->attributes->getSearchableAttributeIds(self::SCRATCH_SHOP_ID));
    }

    /**
     * An attribute carrying neither role is not configured at all - storing it
     * would put a row in the table that no screen and no indexer looks at.
     */
    public function testAnAttributeWithNoRoleIsNotStored(): void
    {
        $this->attributes->save(self::SCRATCH_SHOP_ID, [
            ['attributeId' => 'attr-a', 'facet' => false, 'searchable' => false],
        ]);

        $this->assertSame([], $this->attributes->getRows(self::SCRATCH_SHOP_ID));
    }

    /**
     * A save replaces the whole arrangement rather than adding to it, or a
     * dragged-out attribute would keep filtering the shop for ever.
     */
    public function testASaveReplacesWhatWasThereBefore(): void
    {
        $this->attributes->save(self::SCRATCH_SHOP_ID, [
            ['attributeId' => 'attr-a', 'facet' => true, 'searchable' => false],
        ]);
        $this->attributes->save(self::SCRATCH_SHOP_ID, [
            ['attributeId' => 'attr-b', 'facet' => true, 'searchable' => false],
        ]);

        $this->assertSame(
            ['attr-b'],
            array_column($this->attributes->getRows(self::SCRATCH_SHOP_ID), 'FOUN10ATTRID')
        );
    }

    /**
     * Rows are cached per shop for the request, so a save has to invalidate
     * that cache - a screen that saves and then renders would otherwise show
     * the arrangement it just replaced.
     */
    public function testASaveIsVisibleImmediatelyRatherThanAfterTheNextRequest(): void
    {
        $this->attributes->save(self::SCRATCH_SHOP_ID, [
            ['attributeId' => 'attr-a', 'facet' => true, 'searchable' => false],
        ]);

        $this->attributes->getRows(self::SCRATCH_SHOP_ID);

        $this->attributes->save(self::SCRATCH_SHOP_ID, [
            ['attributeId' => 'attr-b', 'facet' => true, 'searchable' => false],
        ]);

        $this->assertSame(
            ['attr-b'],
            array_column($this->attributes->getRows(self::SCRATCH_SHOP_ID), 'FOUN10ATTRID')
        );
    }

    // ---------------------------------------------------------------
    // the labels a merchant types
    // ---------------------------------------------------------------

    public function testALabelSurvivesTheRoundTripPerLanguage(): void
    {
        $this->attributes->saveTitles(self::SCRATCH_SHOP_ID, [
            'attr-a' => [0 => 'Grundfarbe', 1 => 'Base colour'],
        ]);

        $this->assertSame(
            ['attr-a' => 'Grundfarbe'],
            $this->attributes->getCustomTitles(self::SCRATCH_SHOP_ID, 0)
        );
        $this->assertSame(
            ['attr-a' => 'Base colour'],
            $this->attributes->getCustomTitles(self::SCRATCH_SHOP_ID, 1)
        );
    }

    /**
     * A label is customer-facing text, so it has to come back exactly as typed
     * - which is what a utf8mb4 column is for.
     */
    public function testALabelKeepsItsUmlautsAndPunctuation(): void
    {
        $label = "Größe (Damen) – z. B. „38\"";

        $this->attributes->saveTitles(self::SCRATCH_SHOP_ID, ['attr-a' => [0 => $label]]);

        $this->assertSame($label, $this->attributes->getCustomTitles(self::SCRATCH_SHOP_ID, 0)['attr-a']);
    }

    /**
     * The module's own text columns are utf8mb4, which is the part it controls.
     *
     * Four-byte characters still do not survive a round trip on a stock OXID 7
     * CE, and that is worth knowing rather than worth fixing here: the shop's
     * own tables are utf8mb3, so the connection is too, and an emoji is
     * replaced with "?" on its way in - before the module's column ever sees
     * it. Nothing a module can do about that; changing the connection charset
     * is an installation-wide decision.
     *
     * What this pins is that the migration does not make it worse by declaring
     * a narrower charset than the label may need.
     */
    public function testTheLabelColumnIsWideEnoughForAnythingTheConnectionDelivers(): void
    {
        $rows = DatabaseHelper::fetchAll(
            "SELECT CHARACTER_SET_NAME AS charset
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'foun10easysearchattributetitle'
               AND COLUMN_NAME = 'FOUN10TITLE'"
        );

        $this->assertNotSame([], $rows, 'The label column is missing - has the migration run?');
        $this->assertSame('utf8mb4', $rows[0]['charset']);
    }

    // ---------------------------------------------------------------
    // synonym rules
    // ---------------------------------------------------------------

    public function testARuleSurvivesTheRoundTrip(): void
    {
        $stored = $this->synonyms->save(self::SCRATCH_SHOP_ID, self::LANG_ID, [
            ['type' => SynonymRule::TYPE_ONEWAY, 'term' => 'bralette', 'synonyms' => 'triangel', 'active' => true],
        ]);

        $this->assertSame(1, $stored);

        $rules = $this->synonyms->getRules(self::SCRATCH_SHOP_ID, self::LANG_ID);

        $this->assertCount(1, $rules);
        $this->assertSame(SynonymRule::TYPE_ONEWAY, $rules[0]->getType());
        $this->assertSame('bralette', $rules[0]->getTerm());
        $this->assertSame('triangel', $rules[0]->getSynonyms());
        $this->assertTrue($rules[0]->isActive());
    }

    public function testAnIncompleteRuleIsNotStored(): void
    {
        $stored = $this->synonyms->save(self::SCRATCH_SHOP_ID, self::LANG_ID, [
            ['type' => SynonymRule::TYPE_BOTH, 'term' => 'bralette', 'synonyms' => '', 'active' => true],
            ['type' => SynonymRule::TYPE_BOTH, 'term' => '', 'synonyms' => 'triangel', 'active' => true],
        ]);

        $this->assertSame(0, $stored);
        $this->assertSame([], $this->synonyms->getRules(self::SCRATCH_SHOP_ID, self::LANG_ID));
    }

    public function testAnInactiveRuleIsStoredButNotHandedToTheSearch(): void
    {
        $this->synonyms->save(self::SCRATCH_SHOP_ID, self::LANG_ID, [
            ['type' => SynonymRule::TYPE_BOTH, 'term' => 'aktiv', 'synonyms' => 'eins', 'active' => true],
            ['type' => SynonymRule::TYPE_BOTH, 'term' => 'inaktiv', 'synonyms' => 'zwei', 'active' => false],
        ]);

        $this->assertCount(2, $this->synonyms->getRules(self::SCRATCH_SHOP_ID, self::LANG_ID));
        $this->assertSame(
            ['aktiv'],
            array_map(
                static fn (SynonymRule $rule): string => $rule->getTerm(),
                $this->synonyms->getActiveRules(self::SCRATCH_SHOP_ID, self::LANG_ID)
            )
        );
    }

    /**
     * Rules belong to a shop *and* a language, and the screen only ever has one
     * language in front of it - so a save must not reach into another one.
     */
    public function testASaveTouchesOnlyTheLanguageItWasGiven(): void
    {
        $this->synonyms->save(self::SCRATCH_SHOP_ID, 0, [
            ['type' => SynonymRule::TYPE_BOTH, 'term' => 'deutsch', 'synonyms' => 'german', 'active' => true],
        ]);
        $this->synonyms->save(self::SCRATCH_SHOP_ID, 1, [
            ['type' => SynonymRule::TYPE_BOTH, 'term' => 'english', 'synonyms' => 'englisch', 'active' => true],
        ]);

        $this->assertSame('deutsch', $this->synonyms->getRules(self::SCRATCH_SHOP_ID, 0)[0]->getTerm());
        $this->assertSame('english', $this->synonyms->getRules(self::SCRATCH_SHOP_ID, 1)[0]->getTerm());
    }

    public function testASavedRuleIsVisibleImmediately(): void
    {
        $this->synonyms->save(self::SCRATCH_SHOP_ID, self::LANG_ID, [
            ['type' => SynonymRule::TYPE_BOTH, 'term' => 'erste', 'synonyms' => 'eins', 'active' => true],
        ]);

        $this->synonyms->getRules(self::SCRATCH_SHOP_ID, self::LANG_ID);

        $this->synonyms->save(self::SCRATCH_SHOP_ID, self::LANG_ID, [
            ['type' => SynonymRule::TYPE_BOTH, 'term' => 'zweite', 'synonyms' => 'zwei', 'active' => true],
        ]);

        $this->assertSame('zweite', $this->synonyms->getRules(self::SCRATCH_SHOP_ID, self::LANG_ID)[0]->getTerm());
    }

    /**
     * Two rules for the same term in one save would collide on the primary key
     * if it were derived from the term alone.
     */
    public function testTwoRulesForTheSameTermDoNotCollide(): void
    {
        $stored = $this->synonyms->save(self::SCRATCH_SHOP_ID, self::LANG_ID, [
            ['type' => SynonymRule::TYPE_BOTH, 'term' => 'bh', 'synonyms' => 'bustier', 'active' => true],
            ['type' => SynonymRule::TYPE_ONEWAY, 'term' => 'bh', 'synonyms' => 'korsage', 'active' => true],
        ]);

        $this->assertSame(2, $stored);
        $this->assertCount(2, $this->synonyms->getRules(self::SCRATCH_SHOP_ID, self::LANG_ID));
    }

    /**
     * The scratch shop has to be genuinely empty at the start, or every
     * assertion above is reading somebody else's rows.
     */
    public function testTheScratchShopIsIsolated(): void
    {
        $rows = DatabaseHelper::fetchAll(
            'SELECT COUNT(*) AS n FROM foun10easysearchsynonym WHERE OXSHOPID = :shopId',
            [':shopId' => self::SCRATCH_SHOP_ID]
        );

        $this->assertSame(0, (int) $rows[0]['n']);
    }
}
