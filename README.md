# EasySearch

[![CI b-7.x](https://img.shields.io/github/actions/workflow/status/foun10/easy-search-oxid/ci.yml?branch=b-7.x&label=CI%20b-7.x)](https://github.com/foun10/easy-search-oxid/actions/workflows/ci.yml?query=branch%3Ab-7.x)
[![Latest Release](https://img.shields.io/github/v/release/foun10/easy-search-oxid?sort=semver)](https://github.com/foun10/easy-search-oxid/releases)
[![PHP](https://img.shields.io/badge/PHP-%5E8.1-777BB4?logo=php&logoColor=white)](#compatibility)
[![OXID eShop](https://img.shields.io/badge/OXID%20eShop-7.0%20%E2%80%93%207.5-e30613)](#compatibility)
[![License](https://img.shields.io/badge/license-GPL--3.0--only-blue)](LICENSE)

> A product search for OXID eShop with typo tolerance, faceted filtering and suggest.
> The engine sits behind an interface, so whether a shop is answered by its own MySQL
> database or by a Meilisearch service is a setting, not a deployment.

---

## Compatibility

| Module version | Branch | OXID eShop | Template engine |
|---|---|---|---|
| 7.x | [`b-7.x`](https://github.com/foun10/easy-search-oxid/tree/b-7.x) | 7.0 – 7.5 | Twig |

The major version tracks the OXID line this module targets. There is no OXID 6 line
today; if one is added it will be `6.x` on a `b-6.x` branch.

**It can be made to run on OXID 6, and we have done that kind of port before.** The parts
that do the actual work — the engines, the indexer, the correction dictionary, the facet
counting — are plain PHP with no shop framework in them, and they carry over unchanged.
What has to be adapted is the layer around them: the module uses readonly properties and
other PHP 8.1 syntax that a 7.4 floor does not allow, its admin and storefront templates
are Twig where OXID 6 is Smarty, and the storefront integration relies on OXID 7's
theme-extension convention rather than Smarty's block system. That is a real piece of
work, not a switch in the settings — so if you need EasySearch on an OXID 6 shop,
[get in touch](#like-this-module) and we will talk about what it takes.

### Tested combinations

Every row below is installed from scratch and exercised by the full test suite on every
push. This is not a statement of intent — if a combination is listed here, CI proves it.

<!-- ci-matrix:start -->

| OXID eShop | PHP |
|---|---|
| 7.0 | 8.1 |
| 7.1 | 8.1, 8.2 |
| 7.2 | 8.2, 8.3 |
| 7.3 | 8.2, 8.3, 8.4 |
| 7.4 | 8.2, 8.3, 8.4 |
| 7.5 | 8.3, 8.4, 8.5 |

<!-- ci-matrix:end -->

PHP 8.1 is the floor because the module uses readonly properties throughout. That is why
OXID 7.0 appears with 8.1 only, although the shop itself also runs on 8.0.

## Features

- **Typo tolerance that knows your catalogue.** Corrections are drawn from a dictionary
  built out of your own indexed products, scored by Damerau-Levenshtein distance and
  Cologne phonetics, so a misspelling is corrected towards a word that actually returns
  results rather than towards a dictionary word that does not.
- **Faceted filtering that counts correctly.** Filtering is checked per variant, so a
  size 38 blouse never appears under "size 42" because a sibling variant matches — while
  the numbers beside each facet value are counted per product, which is what a shopper
  expects to see.
- **Suggest as you type**, combining term completions from the same dictionary with
  matching products and categories.
- **Synonym rules per shop and per language**, maintained in the backend and applied at
  query time, so a saved rule takes effect on the next search rather than after the next
  reindex.
- **Search reporting.** Searches per day or month, the most used terms, and — most
  usefully — the terms that found nothing, each with a button that opens the synonym
  screen with that term already filled in.
- **Two interchangeable engines.** MySQL/MariaDB needs nothing but the shop database;
  Meilisearch needs a service beside it. Both answer the same interface, and
  `foun10:easysearch:benchmark` runs the same queries through both, so the choice is made
  on numbers.

## Installation

```bash
composer require foun10/easysearch
```

Then, from the shop root:

```bash
vendor/bin/oe-eshop-db_migrate migrations:migrate foun10EasySearch
vendor/bin/oe-console oe:module:activate foun10EasySearch
vendor/bin/oe-console foun10:easysearch:reindex
```

Until an index exists the module reports itself unavailable and the shop falls back to
its own search, so a half-installed module never takes the storefront down.

> **Storefront integration.** The module ships the JavaScript for the facet sidebar and
> the suggest dropdown, but no theme templates — the markup has to be placed in your
> theme. See [Storefront integration](#storefront-integration) below.

### Optional: Meilisearch

```bash
vendor/bin/oe-console foun10:easysearch:reindex --engine=meilisearch --shop-id=1
```

Then set `FOUN10EASYSEARCH_ENGINE` to `meilisearch` for that shop and clear the cache.
Both indexes can be kept current side by side, which is what the benchmark command
compares.

## Configuration

Per subshop, in the module configuration.

| Setting | Type | Default | Meaning |
|---|---|---|---|
| `FOUN10EASYSEARCH_ENGINE` | select | `mysql` | `mysql`, `meilisearch` or `null` |
| `FOUN10EASYSEARCH_MEILI_HOST` | str | – | Fallback only; `MEILI_HOST` from the environment wins |
| `FOUN10EASYSEARCH_MEILI_KEY` | password | – | Fallback only; `MEILI_MASTER_KEY` from the environment wins |
| `FOUN10EASYSEARCH_MEILI_PREFIX` | str | – | Index name prefix, default `foun10easysearch` |
| `FOUN10EASYSEARCH_MIN_TERM_LENGTH` | num | `2` | Shorter input is not searched |
| `FOUN10EASYSEARCH_LOG_ENABLED` | bool | `true` | Count search terms into `foun10easysearchlog` |
| `FOUN10EASYSEARCH_PARENT_ATTRIBUTES` | bool | `true` | Whether variants inherit the parent's attributes — see the note below |
| `FOUN10EASYSEARCH_CORRECTION_ENABLED` | bool | `true` | Typo tolerance on/off |
| `FOUN10EASYSEARCH_CORRECTION_AUTO_APPLY` | bool | `true` | Replace the term, or only offer "did you mean" |
| `FOUN10EASYSEARCH_CORRECTION_MAX_HITS` | num | `0` | Correct only at or below this hit count; `0` means only when nothing was found |
| `FOUN10EASYSEARCH_CORRECTION_MIN_FREQUENCY` | num | `2` | Never correct towards a term rarer than this |
| `FOUN10EASYSEARCH_SHOW_CORRECTION` | bool | `true` | Show the "showing results for …" notice |
| `FOUN10EASYSEARCH_FACET_VALUE_LIMIT` | num | `30` | Values rendered per facet |
| `FOUN10EASYSEARCH_SUGGEST_LIMIT_TERMS` | num | `6` | Terms in the suggest dropdown |
| `FOUN10EASYSEARCH_SUGGEST_LIMIT_PRODUCTS` | num | `6` | Products in the suggest dropdown |

**On `PARENT_ATTRIBUTES`:** this depends entirely on how your catalogue is maintained.
Where a parent carries only what all its variants share — material, care symbols —
inheriting is right. Where it carries the union of its variants' values, every variant
ends up claiming every size its siblings have and the size filter stops meaning anything.
Turn it off in that case.

## Admin screens

All screens live under **foun10 → Search** and are configured **per subshop**.

| Screen | What it does |
|---|---|
| Attributes | Drag attributes into the filter sidebar and into the searchable text; the order of the list is the order the sidebar renders. Per attribute: how the facet is displayed, and a customer-facing label per language. |
| Synonyms | Synonym rules, per shop and per language. |
| Index | Index status, and a browser-driven rebuild. |
| Reporting | Read-only: searches per day or month, the most used terms, and the terms that found nothing. |

The editorial screens write to their own tables rather than to module settings, so
`oe:module:deploy-configurations` cannot overwrite what a merchant arranged.

The reindex button drives the rebuild from the browser in phases — `clear` → `index` →
`category` → `dictionary` — because a web request cannot hold a full catalogue rebuild
open. The cursor lives in the client, so the endpoint stays stateless and closing the tab
halfway through leaves nothing to clean up. **The batch size tunes itself:** the browser
times every tick and asks for the size that would land near four seconds, bounded to
50…2000 documents. A fixed number is wrong on one machine by definition — building a batch
costs about a second almost regardless of its size, so the right batch is however much
catalogue fits beside that fixed cost on the machine at hand.

Reporting covers one calendar period at a time — today, this month, this year — because a
merchant comparing this month against the last one cannot do that with a window that moves
under them every night. Its lists leave out what is not a search: injection payloads,
whole URLs, code fragments. That filter runs at both ends, so the writer never stores such
input and the screen drops whatever was stored before the filter existed. A toggle shows
them anyway, each with the reason it was sorted out.

## Commands

The module registers four console commands.

| Command | What it does |
|---|---|
| `foun10:easysearch:reindex` | Rebuilds the index. Scope with `--shop-id` / `--lang-id`, choose the target with `--engine`, or run the cheap parts alone with `--categories-only` / `--dictionary-only` |
| `foun10:easysearch:benchmark` | Runs the same searches through every configured engine and prints what they cost, optionally against a `--json` file from an earlier run |
| `foun10:easysearch:log` | Reports on what customers searched for, from the command line |
| `foun10:easysearch:doctor` | Reads the server and the index and says what to do about what it finds — see [Performance](#performance). `--strict` exits non-zero when something needs doing |

### Suggested cron

```cron
# category assignments move with every catalogue import - refresh them often
0 */4 * * *  cd /var/www/shop && vendor/bin/oe-console foun10:easysearch:reindex --categories-only

# full rebuild overnight
30 2 * * *   cd /var/www/shop && vendor/bin/oe-console foun10:easysearch:reindex
```

The benchmark takes its search terms from your own data — the last 90 days of the search
log, falling back to the catalogue dictionary. A benchmark against a hand-written list
only measures how the engines handle that list.

## How a request is answered

```
request  →  RequestQueryFactory  →  SearchQuery  →  SearchEngineInterface
                                                         ↓
                                        SearchResult (product IDs + facets)
                                                         ↓
                                     controller loads the articles and renders
```

The engine returns **IDs, not products.** The controller loads them through OXID, so
prices, pictures and links stay live instead of frozen at the last reindex.

Three pages are served this way, and they all filter identically:

| Page | Hooked at | Narrowed by |
|---|---|---|
| Search | `Search` model (`getSearchArticles`) | the search term |
| Category | `ArticleListController::loadArticles()` | the category index, matched on the product group |
| Manufacturer | `ManufacturerListController::loadArticles()` | the manufacturer column on the index row |

Each falls back to the shop's own listing when the engine cannot serve the scope, so an
unbuilt index degrades quietly instead of emptying the shop.

## Tables

Two kinds, and the difference matters.

**Index artifacts** are derived, disposable and rebuilt by the command. Only the MySQL
connector uses them; on Meilisearch the same information lives inside the documents. There
is one set per subshop, named `…_s1`, `…_s2` and so on, and they are **created by the
rebuild rather than by a migration** — a shop that has never been indexed simply has no
tables, which the engine reports as "not available".

| Table | One row per | Answers |
|---|---|---|
| `foun10easysearchindex` | variant, or article without variants | matching: fulltext, visibility, price, stock, manufacturer, sorting |
| `foun10easysearchindexattribute` | variant × facet value | filtering, checked per variant |
| `foun10easysearchindexattributegroup` | product × facet value | the counts beside the facet values |
| `foun10easysearchindexcategory` | product × category | which products a category listing shows |
| `foun10easysearchdictionary` | term | typo correction and suggest completions |

**Editorial tables** hold what a merchant maintains and are created by migration, because
losing one loses work: `foun10easysearchattribute`, `foun10easysearchattributetitle`,
`foun10easysearchsynonym` and `foun10easysearchlog`.

## Connectors

The shop-facing code talks to interfaces only. A different backend means implementing
these, and nothing else.

| Contract | Responsibility |
|---|---|
| `SearchEngineInterface` | `search()`, `suggest()`, `isAvailable()` — takes a `SearchQuery`, returns IDs and facets |
| `IndexWriterInterface` | `begin` / `write` / `commit` / `rollback`, plus `resume()` for the browser-driven rebuild, `delete()` for incremental updates, and `rebuildCategories()` |
| `IndexDocument` | Backend-neutral document; `toArray()` is already close to what a document store wants |

`DocumentProvider`, `Normalizer`, `SpellCorrector`, `SynonymExpander`, `FacetPresentation`
and `ArticleListFactory` are backend-neutral and meant to be reused as they are. Wiring
lives in `services.yaml`:

```yaml
foun10\EasySearch\Engine\SearchEngineInterface:
  alias: foun10\EasySearch\Engine\MySql\MySqlSearchEngine
  public: true
```

### MySQL / MariaDB

Matching is `MATCH … AGAINST` in boolean mode over the indexed text, with a separately
weighted boost column for title, brand and article number so exact title matches stay on
top. A full rebuild loads into shadow tables and swaps them in with one atomic
`RENAME TABLE`; fulltext indexes are dropped while bulk loading and added back before the
swap, which is roughly an order of magnitude faster than loading into a live index.

### Meilisearch

Needs a Meilisearch service reachable from the shop. Host and key come from the
environment (`MEILI_HOST`, `MEILI_MASTER_KEY`) with the module settings as a fallback, so
credentials do not have to live in the shop configuration.

## Performance

On Meilisearch this section does not apply — the service holds its own index and answers
from memory. On the MySQL connector, search speed is decided by three server settings, and
none of them is a module setting. Two of the three need a database restart, so this is
worth checking before a launch rather than after.

**Do not guess at them — run the doctor.** It reads the settings, measures how much room
the index actually takes in your shop, runs one real search, and prints each finding with
the numbers behind it and the command to fix it.

```bash
vendor/bin/oe-console foun10:easysearch:doctor
```

```
foun10 EasySearch - server and index check
==========================================

Server
------

 -------------------------- -------- 
  setting                    value   
 -------------------------- -------- 
  version                    8.4.11  
  innodb_buffer_pool_size    128 MB  
  innodb_ft_min_token_size   3       
  max_allowed_packet         64 MB   
 -------------------------- -------- 

Index
-----

 ---------------------------------------- --------- ------------------ -------- 
  table                                    state     rows (estimated)   size    
 ---------------------------------------- --------- ------------------ -------- 
  foun10easysearchindex_s1                 present   338                368 KB  
  foun10easysearchindexattribute_s1        present   0                  64 KB   
  foun10easysearchindexattributegroup_s1   present   0                  48 KB   
  foun10easysearchindexcategory_s1         present   206                48 KB   
  foun10easysearchdictionary               present   302                80 KB   
 ---------------------------------------- --------- ------------------ -------- 

 Search tables together: 608 KB

Findings
--------

  [hint] Short terms cannot use the fulltext index
    innodb_ft_min_token_size is 3, so these words fall back to a LIKE scan: r8 (50), r9 (30), ox (20), hs (20), kk (10).
    The number in brackets is how often the catalogue uses the word.
    Set innodb_ft_min_token_size=2, restart, and rebuild the index.

  [ok] The InnoDB buffer pool has room for the index
    Pool 128 MB against 608 KB of search tables.
```

That is a demo shop, where everything fits. On a real catalogue the first finding tends to
read like this instead, and it is the one to act on:

```
  [problem] The InnoDB buffer pool is smaller than the search index needs
    Pool 128 MB against 412 MB of search tables, so facet queries read pages from disk.
    Set innodb_buffer_pool_size to at least 1 GB and restart the database.
    Measured on staging: the same search went from 10,368 ms to 236 ms on this change alone.
```

### The three settings

| Setting | Default | Why the search cares |
|---|---|---|
| `innodb_buffer_pool_size` | 128 MB | A facet query touches the whole index. If the index does not fit in the pool, every search reads pages from disk — this is the one that turns a fast search into a slow one. |
| `innodb_ft_min_token_size` | 3 | Words shorter than this are **not in the fulltext index at all**. A search for them falls back to a `LIKE` scan over the table. |
| `max_allowed_packet` | 4–64 MB | A rebuild writes up to 2,000 documents in one `INSERT`, roughly 3 MB. Too small and the rebuild fails rather than slows. |

**The buffer pool** is the one that matters. Aim for at least **twice** what the doctor
reports under "Search tables together" — the pool also holds `oxarticles`, `oxcategories`
and the SEO tables, so matching the search index alone is the floor, not the target. This
is not a marginal setting: on our own staging the same search went from **10,368 ms to
236 ms** on this change alone, with no other difference. The doctor reports it as a
*problem* when the pool is smaller than the index and as a *hint* when it is smaller than
twice the index.

```ini
# my.cnf — a dedicated database server can take ~70% of RAM
innodb_buffer_pool_size = 2G
```

**Short terms** matter only for some catalogues, which is why the doctor answers it from
your data rather than in general: it lists the shortest words your own catalogue actually
uses, with how often each occurs. Size codes (`XS`, `38`), two-letter brand abbreviations
and ERP short names are the usual case, and when one of those sits at the top of the list
it is likely also a frequent search. Lowering it needs a restart **and a rebuild**, because
the fulltext index has to be built again:

```ini
innodb_ft_min_token_size = 2
```

```bash
vendor/bin/oe-console foun10:easysearch:reindex
```

Note that InnoDB's stopword list is separate from the module's. MySQL ships a short English
one (`the`, `and`, `is`, …) and applies it to the fulltext index regardless of the shop's
language, so those words cannot be searched for on their own no matter what the module
does. Replace it with `innodb_ft_server_stopword_table` if that matters for your catalogue.

### If you cannot change any of this

Shared or managed hosting often does not let you. In that case the honest answer is that
the MySQL connector will be slow on a large catalogue and the module cannot fix it —
switch to Meilisearch, or keep the shop's own search. `--strict` makes the doctor exit
non-zero when something needs doing, which is enough to watch it from a monitoring job:

```bash
vendor/bin/oe-console foun10:easysearch:doctor --strict
```

## Storefront integration

**On the Apex theme it integrates itself.** Activate the module, rebuild the index, and the
search page and every category listing have a filter button, an offcanvas panel, chips for
the active filters and a suggest dropdown under the search box — without a line changed in
the theme.

That works through OXID 7's theme-extension convention: files under
`views/twig/extensions/themes/apex/` whose paths mirror the shop templates they join, each
calling `{{ parent() }}` first so the theme renders exactly what it rendered before and the
module's markup follows.

**On any other theme, nothing appears — by design.** Those files are scoped to `apex`
rather than to OXID's catch-all `default` on purpose. The block names in them are apex's,
and so is the markup and the stylesheet that were shaped against it. A theme naming its
blocks differently would get nothing; one naming them the same would get markup built for
somebody else's layout, which is the worse of the two because it looks like it worked.

### Putting it on your own theme

Copy the directory to your theme's id and adjust:

```
cp -r views/twig/extensions/themes/apex views/twig/extensions/themes/<yourtheme>
```

Four files, none of which contains markup of its own — each only says *where* something
goes, by extending one of your theme's templates and overriding a block:

| Extension file | Block it extends | What it adds |
|---|---|---|
| `page/search/search.html.twig` | `search_header`, `search_results` | correction line, chips, the panel |
| `page/list/list.html.twig` | `page_list_upperlocator`, `page_list_listbody` | chips, the panel |
| `widget/locator/attributes.html.twig` | `widget_locator_attributes` | the filter button, into the row above the grid |
| `widget/header/search.html.twig` | `dd_widget_header_search_form_inner` | the suggest dropdown container |

Point each at a block your theme actually defines. The markup itself lives in the partials
below, and those are theme-independent:

| Template | What it renders |
|---|---|
| `frontend/toolbar.html.twig` | the button that opens the panel, with a badge counting active filters |
| `frontend/panel.html.twig` | the filter panel itself, as a Bootstrap offcanvas |
| `frontend/facets.html.twig` | the facet groups, included by the panel |
| `frontend/active-filters.html.twig` | a removable chip per selected value |
| `frontend/suggest.html.twig` | a complete search box, for a theme that would rather replace its own |
| `frontend/correction.html.twig` | the "showing results for …" line |

To change how something looks, restyle the classes, or drop
`assets/out/css/frontend.css` and write your own; it only styles `foun10-*` classes and
takes its colours from the theme's CSS variables.

### Replacing one of these partials from your own project

A theme cannot override a module's template — theme directories are registered in Twig's
main namespace, and `@foun10EasySearch/…` resolves only against module directories. Another
**module** can, which is the OXID 7 equivalent of the Smarty block override, and is the
right home for project-specific changes anyway: they survive an update of this module.

In a module of your own, mirror the path under `extensions/modules/`:

```
yourmodule/views/twig/extensions/modules/foun10EasySearch/frontend/active-filters.html.twig
```

Every partial is wrapped in a named block, so you can adjust one part rather than restate
the file:

```twig
{% extends "@foun10EasySearch/frontend/active-filters.html.twig" %}

{% block foun10_easysearch_active_filters %}
    <div class="my-own-wrapper">{{ parent() }}</div>
{% endblock %}
```

Leave out the `extends` and the file replaces the original outright, which is occasionally
what you want. The block names are `foun10_easysearch_` plus the file's own name:
`facets`, `panel`, `toolbar`, `active_filters`, `suggest`, `correction`.

| Template | Where it goes |
|---|---|
| `frontend/toolbar.html.twig` | above the product grid — the button that opens the panel, with a badge counting active filters |
| `frontend/panel.html.twig` | anywhere on the page — the filter panel itself, as a Bootstrap offcanvas |
| `frontend/facets.html.twig` | included *by* the panel — the facet groups |
| `frontend/active-filters.html.twig` | above the result list — a removable chip per selected value |
| `frontend/suggest.html.twig` | in the header — the search box plus the container the dropdown is built into |
| `frontend/correction.html.twig` | top of the search results — the "showing results for …" line and the headline |

Any of them can also be included directly, if you would rather place one somewhere the
extension templates do not reach:

```twig
{% include "@foun10EasySearch/frontend/active-filters.html.twig" %}
```

The panel is an offcanvas at every breakpoint rather than a sidebar on desktop: one control
opens everything that narrows the list, and the same markup then serves the search page,
category and manufacturer listings without a second layout to keep in step.

**A facet value inside the panel is not a link, and should not become one.** The panel
collects the selection in the DOM and navigates exactly once, when the customer presses
apply — a link per value is a second, competing navigation, and inside an offcanvas the
two race each other. The chips rendered by `active-filters.html.twig` *are* links, because
there a click is the whole interaction: switch this one value off. Those work with no
JavaScript at all, which is why it is worth rendering them even if you drop everything
else.

A value that leads nowhere is rendered but hidden, never struck through — a column of
crossed-out options reads as a broken shop — and stays in the markup so the script can
bring it back when another selection makes it reachable again.

If you restyle rather than copy, keep the class names that carry behaviour — `facets.js`
finds the panel through them, and builds the same structure itself for values that were
not in the server's markup:

| Selector | Meaning |
|---|---|
| `#searchFacets` | the panel (the id is configurable) |
| `.foun10-facets` | the container new groups are appended to |
| `.foun10-facet[data-facet-id]` | one filter group |
| `.foun10-facet__values` | where a group's values live |
| `[data-value]` | one value; `active` = selected, `disabled` = leads nowhere |
| `.foun10-facet__selected` | the per-group count badge |
| `.foun10-facets__apply-button` | the button carrying the total |

The examples use their own language idents, shipped under `views/frontend_twig/` in German
and English. A theme that replaces them with its own strings does not need those files.

## Development & Testing

```bash
# Unit tests (no shop required)
composer tests-unit

# Integration tests (require an installed, activated OXID eShop)
composer tests-integration
```

The CI runs these same commands against every supported OXID/PHP combination — see
[.github/workflows/ci.yml](.github/workflows/ci.yml).

## Honest opinion

We wrote this module for our own customer projects, so here is where we would reach for it and
where we would not.

**✅ Use it when**

- You want a search that copes with misspelt input without you teaching it every word.
- You do not want to depend on a third-party service. The MySQL connector runs on the
  database the shop already has.
- Your product attributes are maintained well enough to filter on. The facet sidebar is
  only as good as the attribute data behind it.

**❌ Do not use it when**

- You want more than a search - redirects for particular search terms, campaign landing
  pages, that kind of merchandising. This module answers queries; it does not route them.
- You want control over where individual products land in the result. Ranking is what the
  engine computes, not something you arrange per product.
- You cannot run Meilisearch and cannot tune the InnoDB buffer pool. On the MySQL
  connector with an undersized buffer pool the search is genuinely slow, and no setting in
  this module fixes that. [Performance](#performance) says which settings decide this and
  how `foun10:easysearch:doctor` measures them against your own index — check that before
  ruling the module in or out, because on a managed host you may not be allowed to change
  them.

## Deutsche Kurzbeschreibung

EasySearch ersetzt die Produktsuche von OXID eShop durch eine eigene Suche mit
Fehlertoleranz, Facetten-Filtern und Suggest. Gefiltert wird pro Variante, gezählt pro
Produkt — eine Bluse in Größe 38 taucht also nicht unter „Größe 42" auf, nur weil eine
Geschwister-Variante passt. Als Backend dient wahlweise die Shop-Datenbank (MySQL/MariaDB)
oder ein Meilisearch-Dienst; welches davon, ist eine Einstellung pro Subshop.
Für OXID eShop 7.0 – 7.5.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

See [LICENSE](LICENSE).

## Like this module?

If it saves you time, a ⭐ on this repository genuinely makes our day — and helps other
OXID developers find it.

Found a bug or missing a feature? Open an
[issue](https://github.com/foun10/easy-search-oxid/issues) — we read them.

And if you need a hand with this module, or are wrestling with other OXID eShop
challenges, feel free to reach out. We have been building and running OXID shops for years
and are happy to help — **including a port of this module to OXID 6**, which is possible
with the adjustments described under [Compatibility](#compatibility).

**Contact:** [foun10](https://github.com/foun10) — or — info@foun10.de
