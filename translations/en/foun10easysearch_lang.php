<?php

/**
 * Language entries for the storefront example templates.
 *
 * Only the strings views/twig/frontend/* renders. A theme that copies those
 * templates and replaces the idents with its own does not need this file - it
 * exists so the examples work as they are rather than showing raw idents.
 */
$sLangName = 'English';

$aLang = [
    'charset' => 'UTF-8',

    // Filter panel
    'FOUN10_EASYSEARCH_FILTER' => 'Filter',
    'FOUN10_EASYSEARCH_FILTER_RESET' => 'Reset',
    'FOUN10_EASYSEARCH_FILTER_RESET_ALL' => 'Remove all filters',
    'FOUN10_EASYSEARCH_CLOSE' => 'Close',
    // %s is replaced with the number of results - by the render at first, then
    // by facets.js on every change.
    'FOUN10_EASYSEARCH_SHOW_RESULTS' => 'Show %s results',

    // Search box and suggestions
    'FOUN10_EASYSEARCH_SEARCH_PLACEHOLDER' => 'Search …',
    'FOUN10_EASYSEARCH_SEARCH_SUBMIT' => 'Search',
    'FOUN10_EASYSEARCH_SUGGEST_TERMS' => 'Search terms',
    'FOUN10_EASYSEARCH_SUGGEST_PRODUCTS' => 'Products',
    'FOUN10_EASYSEARCH_SUGGEST_CATEGORIES' => 'Categories',
    // %s is replaced with the total number of results.
    'FOUN10_EASYSEARCH_SUGGEST_ALL' => 'Show all %s results',

    // Correction
    'FOUN10_EASYSEARCH_RESULTS_FOR' => 'Results for',
    'FOUN10_EASYSEARCH_CORRECTION_APPLIED' => 'Showing results for',
    'FOUN10_EASYSEARCH_CORRECTION_INSTEAD' => 'Search instead for',
    'FOUN10_EASYSEARCH_CORRECTION_SUGGESTED' => 'Did you mean',
];
