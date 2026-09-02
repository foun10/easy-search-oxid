<?php
declare(strict_types=1);

namespace foun10\EasySearch\Controller\Admin;

use foun10\EasySearch\Core\DatabaseHelper;
use foun10\EasySearch\Core\ShopLanguages;
use foun10\EasySearch\Index\DictionaryBuilder;
use foun10\EasySearch\Index\MySql\IndexTables;
use OxidEsales\Eshop\Application\Controller\Admin\AdminController;
use OxidEsales\Eshop\Core\Registry;
use Throwable;

/**
 * foun10 -> Suche -> Index
 *
 * Where the index is rebuilt, and where you can see whether it still reflects
 * the catalogue.
 *
 * The attribute screen keeps a button of its own because a change made there
 * only counts after a rebuild, and sending somebody to a second screen to
 * finish what they started is a good way to have it never finished. That button
 * runs the least it can get away with; this screen is where each phase can be
 * run on its own, and where the full rebuild lives.
 */
class IndexController extends AdminController
{
    use ReindexPhases;

    protected $_sThisTemplate = '@foun10EasySearch/admin/index';

    /**
     * What each button rebuilds, in the order the browser walks it.
     *
     * Phases are independent steps rather than a fixed chain, so a set is just
     * a list. "products" deliberately clears first: the index rows for the
     * scope have to go before they can be written again.
     */
    protected const PHASE_SETS = [
        'full' => [
            Reindex::PHASE_CLEAR,
            Reindex::PHASE_INDEX,
            Reindex::PHASE_CATEGORY,
            Reindex::PHASE_DICTIONARY,
        ],
        'products' => [Reindex::PHASE_CLEAR, Reindex::PHASE_INDEX],
        'categories' => [Reindex::PHASE_CATEGORY],
        'dictionary' => [Reindex::PHASE_DICTIONARY],
    ];

    /**
     * What the index holds for the language currently shown, and when each part
     * was last written.
     *
     * One language at a time rather than a row per language: the buttons below
     * rebuild what is selected, and a table listing every language beside
     * buttons that act on one of them invites exactly the wrong conclusion.
     *
     * @return array<string, mixed>
     */
    public function getIndexStatus(): array
    {
        $shopId = $this->getEditShopId();
        $langId = $this->getEditLanguageId();
        $tables = $this->getService(IndexTables::class);

        return [
            'langId' => $langId,
            'documents' => $this->count($tables->index($shopId), $shopId, $langId),
            'documentsAt' => $this->lastWrite($tables->index($shopId), $shopId, $langId),
            'categories' => $this->count($tables->category($shopId), $shopId, $langId),
            'categoriesAt' => $this->lastWrite($tables->category($shopId), $shopId, $langId),
            'terms' => $this->count(DictionaryBuilder::TABLE, $shopId, $langId),
            'termsAt' => $this->lastWrite(DictionaryBuilder::TABLE, $shopId, $langId),
        ];
    }

    /**
     * The languages this shop serves, for the switch above the table.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getLanguages(): array
    {
        $languages = [];

        foreach ($this->getService(ShopLanguages::class)->getActive($this->getEditShopId()) as $language) {
            $languages[] = ['id' => $language['id'], 'name' => $language['name']];
        }

        return $languages;
    }

    /**
     * The language the screen is showing, and the one its buttons rebuild.
     *
     * Falls back to the first active language rather than to zero: a shop whose
     * only language is not 0 would otherwise open on a scope it does not have.
     */
    public function getEditLanguageId(): int
    {
        $languages = $this->getLanguages();
        $available = array_column($languages, 'id');
        $requested = $this->getRequest()->getRequestEscapedParameter('indexLang');

        if ($requested !== null && $requested !== '' && in_array($this->toInt($requested), $available, true)) {
            return $this->toInt($requested);
        }

        return $available === [] ? 0 : (int) $available[0];
    }

    /**
     * The scope the buttons drive, as the script expects it.
     *
     * @return array<int, array{langId: int}>
     */
    public function getSelectedScope(): array
    {
        return [['langId' => $this->getEditLanguageId()]];
    }

    /**
     * The phase list each button drives, as JSON for the script.
     *
     * @return array<string, string[]>
     */
    public function getPhaseSets(): array
    {
        return self::PHASE_SETS;
    }

    public function getEditShopId(): int
    {
        return $this->getCurrentShopId();
    }

    protected function count(string $table, int $shopId, int $langId): int
    {
        // A shop that has never been indexed has no table of its own yet, and
        // scalar() answers empty rather than failing - see there.
        return (int) $this->scalar(
            "SELECT COUNT(*) AS VALUE FROM {$table} WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId",
            $shopId,
            $langId
        );
    }

    /**
     * When the scope was last written.
     *
     * Read from the rows themselves rather than kept in a settings row: a
     * timestamp somebody has to remember to update is a timestamp that will
     * eventually lie, and every one of these tables already stamps its rows.
     */
    protected function lastWrite(string $table, int $shopId, int $langId): string
    {
        $value = (string) $this->scalar(
            "SELECT MAX(OXTIMESTAMP) AS VALUE FROM {$table} WHERE OXSHOPID = :shopId AND FOUN10LANGID = :langId",
            $shopId,
            $langId
        );

        if ($value === '' || str_starts_with($value, '0000')) {
            return '';
        }

        return $this->formatDate($value);
    }

    protected function scalar(string $sql, int $shopId, int $langId): mixed
    {
        try {
            $rows = $this->query($sql, [':shopId' => $shopId, ':langId' => $langId]);
        } catch (Throwable $exception) {
            // A table the migration has not created yet reads as empty rather
            // than taking the screen down.
            return '';
        }

        return $rows[0]['VALUE'] ?? '';
    }

    /*
     * The shop touch points. As in DoctorCommand, the database seam sits below
     * the catch rather than above it, so "a table that is not there reads as
     * empty" stays a decision a test can drive.
     */

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<int, array<string, mixed>>
     */
    protected function query(string $sql, array $parameters = []): array
    {
        return DatabaseHelper::fetchAll($sql, $parameters);
    }

    protected function getCurrentShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    protected function formatDate(string $value): string
    {
        return (string) Registry::getUtilsDate()->formatDBDate($value, true);
    }
}
