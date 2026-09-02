<?php declare(strict_types=1);

use foun10\EasySearch\Controller\Admin\AttributeController;
use foun10\EasySearch\Controller\Admin\IndexController;
use foun10\EasySearch\Controller\Admin\SearchLogController;
use foun10\EasySearch\Controller\Admin\SynonymController;
use foun10\EasySearch\Controller\FacetController;
use foun10\EasySearch\Controller\SuggestController;
use foun10\EasySearch\Extension\Application\Controller\ArticleListController;
use foun10\EasySearch\Extension\Application\Controller\ManufacturerListController;
use foun10\EasySearch\Extension\Application\Controller\SearchController;
use foun10\EasySearch\Extension\Application\Model\Search;
use foun10\EasySearch\Extension\Core\ViewConfig;

/**
 * Metadata file for module
 */

$sMetadataVersion = '2.1';

$aModule = [
    'id' => 'foun10EasySearch',
    'title' => 'foun10 - Produktsuche',
    'description' => 'Eigene Produktsuche: Fehlertoleranz, Facetten-Filter und Suggest',
    'thumbnail' => 'foun10.png',
    'version' => '7.0.0',
    'author' => 'foun10 GmbH',
    'email' => 'info@foun10.de',
    'extend' => [
        \OxidEsales\Eshop\Application\Model\Search::class => Search::class,
        \OxidEsales\Eshop\Application\Controller\SearchController::class => SearchController::class,
        \OxidEsales\Eshop\Application\Controller\ArticleListController::class => ArticleListController::class,
        \OxidEsales\Eshop\Application\Controller\ManufacturerListController::class => ManufacturerListController::class,
        \OxidEsales\Eshop\Core\ViewConfig::class => ViewConfig::class,
    ],
    'controllers' => [
        'foun10easysearchsuggest' => SuggestController::class,
        'foun10easysearchfacets' => FacetController::class,
        'foun10easysearchattributes' => AttributeController::class,
        'foun10easysearchsynonyms' => SynonymController::class,
        'foun10easysearchreindex' => IndexController::class,
        'foun10easysearchlog' => SearchLogController::class,
    ],
    'settings' => [
        [
            'group' => 'foun10easysearch_engine',
            'name' => 'FOUN10EASYSEARCH_ENGINE',
            'type' => 'select',
            'constraints' => 'mysql|meilisearch|null',
            'value' => 'mysql',
        ], [
            // Empty on purpose: MEILI_HOST from the environment wins, and these
            // three only carry the fallback for an installation that cannot set
            // environment variables. A URL committed here would be deployed to
            // every environment alike.
            'group' => 'foun10easysearch_engine',
            'name' => 'FOUN10EASYSEARCH_MEILI_HOST',
            'type' => 'str',
            'value' => '',
        ], [
            'group' => 'foun10easysearch_engine',
            'name' => 'FOUN10EASYSEARCH_MEILI_KEY',
            'type' => 'password',
            'value' => '',
        ], [
            'group' => 'foun10easysearch_engine',
            'name' => 'FOUN10EASYSEARCH_MEILI_PREFIX',
            'type' => 'str',
            'value' => '',
        ], [
            // Counting search terms is data collection, however harmless, so it
            // has a switch. Nothing identifying is stored - see SearchLogger.
            'group' => 'foun10easysearch_engine',
            'name' => 'FOUN10EASYSEARCH_LOG_ENABLED',
            'type' => 'bool',
            'value' => true,
        ], [
            // Whether a variant inherits the attributes sitting on its parent.
            //
            // Default true, because a catalogue whose parents carry the shared
            // attributes is the normal case. Turn it off where the ERP writes
            // the union of the variants' values onto the parent as well: the
            // parent then claims every colour and size its variants have
            // between them, and each variant would inherit the lot - a size 38
            // blouse offered under "42" because a sibling has it.
            'group' => 'foun10easysearch_engine',
            'name' => 'FOUN10EASYSEARCH_PARENT_ATTRIBUTES',
            'type' => 'bool',
            'value' => true,
        ], [
            'group' => 'foun10easysearch_engine',
            'name' => 'FOUN10EASYSEARCH_MIN_TERM_LENGTH',
            'type' => 'num',
            'value' => 2,
        ], [
            'group' => 'foun10easysearch_correction',
            'name' => 'FOUN10EASYSEARCH_CORRECTION_ENABLED',
            'type' => 'bool',
            'value' => true,
        ], [
            'group' => 'foun10easysearch_correction',
            'name' => 'FOUN10EASYSEARCH_CORRECTION_AUTO_APPLY',
            'type' => 'bool',
            'value' => true,
        ], [
            'group' => 'foun10easysearch_correction',
            'name' => 'FOUN10EASYSEARCH_CORRECTION_MAX_HITS',
            'type' => 'num',
            'value' => 0,
        ], [
            'group' => 'foun10easysearch_correction',
            'name' => 'FOUN10EASYSEARCH_CORRECTION_MIN_FREQUENCY',
            'type' => 'num',
            'value' => 2,
        ], [
            'group' => 'foun10easysearch_display',
            'name' => 'FOUN10EASYSEARCH_SHOW_CORRECTION',
            'type' => 'bool',
            'value' => true,
        ], [
            'group' => 'foun10easysearch_facets',
            'name' => 'FOUN10EASYSEARCH_FACET_VALUE_LIMIT',
            'type' => 'num',
            'value' => 30,
        ], [
            'group' => 'foun10easysearch_suggest',
            'name' => 'FOUN10EASYSEARCH_SUGGEST_LIMIT_TERMS',
            'type' => 'num',
            'value' => 6,
        ], [
            'group' => 'foun10easysearch_suggest',
            'name' => 'FOUN10EASYSEARCH_SUGGEST_LIMIT_PRODUCTS',
            'type' => 'num',
            'value' => 6,
        ],
    ],
];
