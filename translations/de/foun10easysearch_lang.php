<?php

/**
 * Language entries for the storefront example templates.
 *
 * Only the strings views/twig/frontend/* renders. A theme that copies those
 * templates and replaces the idents with its own does not need this file - it
 * exists so the examples work as they are rather than showing raw idents.
 */
$sLangName = 'Deutsch';

$aLang = [
    'charset' => 'UTF-8',

    // Filterpanel
    'FOUN10_EASYSEARCH_FILTER' => 'Filter',
    'FOUN10_EASYSEARCH_FILTER_RESET' => 'Zurücksetzen',
    'FOUN10_EASYSEARCH_FILTER_RESET_ALL' => 'Alle Filter entfernen',
    'FOUN10_EASYSEARCH_CLOSE' => 'Schließen',
    // %s wird durch die Trefferzahl ersetzt - beim Rendern durch die aktuelle,
    // danach von facets.js bei jeder Änderung neu.
    'FOUN10_EASYSEARCH_SHOW_RESULTS' => '%s Ergebnisse anzeigen',

    // Suchfeld und Vorschläge
    'FOUN10_EASYSEARCH_SEARCH_PLACEHOLDER' => 'Suchen …',
    'FOUN10_EASYSEARCH_SEARCH_SUBMIT' => 'Suchen',
    'FOUN10_EASYSEARCH_SUGGEST_TERMS' => 'Suchbegriffe',
    'FOUN10_EASYSEARCH_SUGGEST_PRODUCTS' => 'Produkte',
    'FOUN10_EASYSEARCH_SUGGEST_CATEGORIES' => 'Kategorien',
    // %s wird durch die Gesamttrefferzahl ersetzt.
    'FOUN10_EASYSEARCH_SUGGEST_ALL' => 'Alle %s Treffer anzeigen',

    // Korrektur
    'FOUN10_EASYSEARCH_RESULTS_FOR' => 'Ergebnisse für',
    'FOUN10_EASYSEARCH_CORRECTION_APPLIED' => 'Ergebnisse für',
    'FOUN10_EASYSEARCH_CORRECTION_INSTEAD' => 'Stattdessen suchen nach',
    'FOUN10_EASYSEARCH_CORRECTION_SUGGESTED' => 'Meinten Sie',
];
