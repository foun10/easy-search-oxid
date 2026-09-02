# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

The major version tracks the OXID line this module targets, not a generation of the module:
`7.x` on the `b-7.x` branch is for OXID 7. There is no OXID 6 line — the module uses PHP 8.1
features throughout and Twig-only admin templates.

## [7.0.0] - 2026-09-02

First public release, for OXID 7.

### Added

- A product search with **typo tolerance drawn from the shop's own catalogue**. The correction
  dictionary is built from the indexed products, and candidates are scored by Damerau-Levenshtein
  distance and Cologne phonetics — so a misspelling is corrected towards a word that actually
  returns results, rather than towards a dictionary word that does not.
- **Faceted filtering that counts the way a shopper reads it.** Whether a variant matches is
  decided per variant, so a size 38 blouse never appears under "size 42" because a sibling
  variant matched; the number beside each facet value counts products, not variant rows.
- A facet endpoint (`cl=foun10easysearchfacets`) that answers **which values are still reachable**
  and how many products the current selection yields, so the filter panel updates without
  reloading the listing.
- **Suggest as you type** (`cl=foun10easysearchsuggest`), combining term completions from the same
  dictionary with matching products and categories, each carrying the path that leads to it.
- **Synonym rules per shop and per language**, applied when the query is built rather than written
  into the index — so a saved rule takes effect on the next search, not after the next rebuild.
  One-way and two-way rules are distinguished.
- **Search reporting**: searches per day or month, the most used terms, and the terms that found
  nothing — each with a button that opens the synonym screen with the term already filled in.
  Rows that are not searches are filtered out, and can be shown on request with the reason.
- **Two interchangeable engines.** MySQL/MariaDB needs nothing but the shop database; Meilisearch
  needs a service beside it. Both implement the same interface, and `foun10:easysearch:benchmark`
  runs the same queries through both so the choice can be made on numbers.
- Rebuilds through a **shadow table and a single swap**, so a failed run leaves the live index
  exactly as it was and the shop keeps answering throughout.
- Four commands: `foun10:easysearch:reindex`, `:benchmark`, `:log` and `:doctor`. The last one
  checks the database server and the index and says what to do about what it finds — it exists
  because a search that took 505 ms locally took 10.4 s on staging, and nothing in the shop said
  the InnoDB buffer pool was the reason.
- A browser-driven rebuild in the backend that walks the catalogue one batch at a time, so a large
  shop can be reindexed without a shell.
- Backend screens for the facet and searchable attributes (with example values, so it is possible
  to tell whether an attribute is worth filtering on), the synonym rules, the index status and the
  search report.
- Nothing about a person is stored. The search log counts one row per term, shop, language and
  day; there is no user, session, IP or agent column.
- **Storefront integration for the Apex theme that needs no theme changes.** The filter toolbar,
  the offcanvas panel and its facet groups, the removable chips for active filters, the
  correction line and the suggest dropdown all appear on the search page and on category
  listings once the module is activated, through OXID 7's theme-extension convention
  (`views/twig/extensions/themes/apex/`) rather than by editing a theme. Every block calls
  `parent()` first, so the theme renders what it always rendered and the module's markup
  follows. On another theme these are a worked example to copy to your own theme id — scoped
  to `apex` rather than OXID's catch-all `default` on purpose, because both the block names
  and the styling are Apex's.
- An example stylesheet that styles only the module's own `foun10-*` classes and takes its
  colours from the theme's CSS variables, so the panel is presentable before anyone restyles it.
- Storefront language files in German and English, and every piece of markup as a separate
  partial under `@foun10EasySearch/frontend/`, each wrapped in a named block, so a project can
  replace one part — the chips, the panel, the suggest — from its own module without taking on
  the rest and without forking the module.

### Fixed

Carried over from the internal version this module grew out of. Anyone who ran that code will
want these.

- The indexer read variant rows without falling back to the parent. In OXID a variant carries
  none of its own text — title, both descriptions, manufacturer, EAN and MPN all live on the
  parent row — so **every product with variants was indexed without its name and could not be
  found by it**. Nothing failed and nothing was logged; a search for a category name still
  worked, which is what made it look healthy.
- The article query joined `oxfield2shop`, a table only OXID Enterprise has. On Community and
  Professional every rebuild died on its first batch and rolled back.
- A derived table was aliased `groups`, which MySQL reserved in 8.0.2. The statement parsed on
  5.7 and was a syntax error on anything newer.
- Constants were declared on a trait, which is PHP 8.2. On PHP 8.1 — inside the module's own
  supported range — that is a fatal parse error, so the module would not have loaded at all.
- Facet values were de-duplicated only when colour grouping was configured, so a variant that
  both inherited an attribute value and carried it itself was counted as two products.
- A rebuild scope holding no articles — a new subshop, a language not yet filled — aborted the
  whole run and rolled back every scope already indexed, because the progress bar was asked for a
  remaining time it had no maximum to compute.
- The search log's junk filter never matched anything: its traversal pattern collapsed to an
  unterminated character class, so `preg_match` failed to compile and the rule silently did
  nothing.
- Request parameters were cast without being checked. `?langId[]=1` became the integer 1 with no
  warning at all — quietly rebuilding or reporting on the wrong language — and the string casts
  raised "Array to string conversion" in the render path.
- Sizes under a megabyte were reported as "0 MB", and the row counts in the index status read as
  empty because `information_schema` returns its columns uppercased.

### Known limitations

- **The storefront markup is a starting point, not a design.** It integrates itself and is
  styled well enough to use, but search results and filters sit in the middle of a shop's
  layout — expect to move a partial or restyle the classes to match a real theme rather than to
  switch it on and be finished.
- **OXID 7 only.** A 6.x line is possible but does not exist: the module uses readonly properties
  throughout, its templates are Twig, and the storefront integration relies on OXID 7's
  theme-extension convention. The engine, indexer and correction code underneath is
  framework-neutral and would carry over — see the README for what a port involves.
- **No manual ranking control.** Where a particular product lands in the result is what the engine
  scored it, not something that can be pinned per term.
- **No search-term redirects or campaign landing pages.** This finds products; it is not a
  merchandising tool.
- On the MySQL connector, performance depends on three server settings — the InnoDB buffer pool
  above all — and none of them is a module setting. `foun10:easysearch:doctor` measures them
  against your own index and prints what to change, but applying it needs database access the
  module does not have, and on managed hosting you may not have it either. The README's
  Performance section documents each one.

[7.0.0]: https://github.com/foun10/easy-search-oxid/releases/tag/v7.0.0
