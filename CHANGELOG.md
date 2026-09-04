# Changelog

All notable changes to FastMagento are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.9.0] - 2026-09-04

### Added
- **Category widgets and sliders from the index.** "Products of category N" collections (CMS
  product widgets, Hyvä product sliders, featured/new home-page blocks) are served without
  loading from EAV. Strict shape recogniser — one category, a visibility list, position /
  price / no sort, an optional base-price range, a limit; anything else stays native. Two id
  sources: *Database* (default) takes the ids from one index-only query built from the widget's
  own SQL, so the page is byte-identical to native including MySQL's undefined order among
  equal prices or positions; *Search index* takes them from Magento's search index (no MySQL at
  all; ties ordered by product id). The products always come from the FastMagento documents.
  Serving > *Serve Category Widgets And Sliders From The Index* / *Widget Product Ids Come From*.

### Changed
- `Setup/Uninstall` no longer removes companion modules' settings (`fastmagento/personalization/*`,
  `fastmagento/event/*`, `fastmagento/checkout/*`); each companion's own Uninstall does that.
- INSTALL.md leads with the manual `indexer:reindex` before the cron alternative.

## [2.8.1] - 2026-09-03

### Added
- **`Setup/Uninstall`.** `module:uninstall --remove-data` now deletes the four OpenSearch
  serving indices, the mview changelog tables, indexer/mview state rows, cron schedule rows,
  flags and the module's settings, and forgets its schema patches so a reinstall re-applies
  them (Magento reverts data patches on uninstall but never schema patches — without this the
  indexers came back in Update-on-Save mode). Previously all of it survived an uninstall.
- **INSTALL.md**: four indexers (reviews), building the indexes before serving on large
  catalogues, the post-deploy FPM/opcache reload, and an uninstall section covering the
  embedded-composer authentication failure.

### Changed
- `fastmagento/cache/warm_paths` defaults to `/` instead of empty.

## [2.8.0] - 2026-09-03

### Added
- **Product reviews served from OpenSearch.** New `fastmagento_review` indexer projects every
  approved review, with its rating votes, into its own index (one document per review; the full
  build streams the review table by keyset so memory is flat at any review count; partial runs
  touch only the changed reviews). The product page's review list, its pager total and every
  review's star votes now come from one search instead of a COUNT, a SELECT and one
  `rating_option_vote` query per review shown. Toggle: FastMagento > Serving > *Serve Product
  Reviews From The Index* (default on; falls back to MySQL when the index is unavailable).
  Existing installs: `bin/magento indexer:reindex fastmagento_review` and put it in schedule
  mode like the other three.

- **Review-form rating set cached.** The three queries behind the "Rating" rows of the
  product-page review form (ratings, their count, their options) now run once per store and
  then come from the cache, invalidated when a rating or option is saved or deleted. With the
  review index above, a product page runs no review or rating query at all.

- **Category menu inline under Varnish.** Magento's Varnish mode turns the menu block into an
  ESI include, so every cache miss paid a second bootstrap, routing pass and web-server hop
  (~120 ms on the 2.4.9 demo). FastMagento now strips the `ttl` from the menu block (Luma and
  Breeze `catalog.topnav`, Hyvä `topmenu_generic`, configurable) so the menu renders inside the
  page from the block cache. Serving > *Render The Category Menu Inline Under Varnish*.
- **Hyvä section data block-cached per store.** The `default-section-data` block (country and
  region list, ~28 ms and two queries per uncached page) is cached for an hour per store and
  currency. Serving > *Cache Hyvä Section Data Per Store*.

- **Layered navigation HTML block-cached.** Keyed on category or search query, applied
  filters, store, currency and customer group; filters are still applied to the product
  collection, only the rendering is cached (~30 ms per uncached listing on the demo). Serving >
  *Cache Layered Navigation HTML*, with lifetime and block names configurable.

- **Current category from the index.** The category controller's `CategoryRepository::get()`,
  the breadcrumb walk (`getParentCategories`) and the design walk (`getParentDesignCategory`)
  are answered from the indexed category document on the storefront: seven to eight queries
  per category and search page. Serving > *Serve The Current Category From The Index*.
- **URL routing from the index.** The URL-rewrite router's request-path lookup is answered
  from the category tree or one term query on the product index (`request_path` is now a
  keyword field — reindex `fastmagento_product` once; the doctor says so until then), and
  the router's follow-up lookup of the resolved system path is answered without a query.
  Redirects, CMS pages, product-in-category paths and custom rewrites still come from
  `url_rewrite`. Category URLs for menus/breadcrumbs come from the tree too. Serving >
  *Resolve Product And Category URLs From The Index*.
- **Filterable-attribute list cached** per store and list class, rebuilt from the EAV config
  cache; cleaned on attribute save. Serving > *Cache The Layer's Filterable Attribute List*.
- **Swatches from the index.** The attribute-option dictionary now carries each option's
  swatch (type, value, admin/store precedence as the helper applies it) and
  `Swatches\Helper\Data::getSwatchesByOptionsId` is answered from it. Reindex
  `fastmagento_attribute_option` once. Serving > *Serve Swatches From The Index*.

### Fixed
- **Product documents' `reviews_count` / `rating_summary` went stale.** No mview subscription
  covered the review tables, so a newly approved review did not change the stars on product
  cards until the next product reindex. The review indexer now pushes the two summary fields to
  the affected product documents as a partial update on every review change.
- **Repeated OpenSearch round-trips for the same product within one request.** The PDP fetch
  helper now memoises documents per request (7 single-document GETs per product page became 2).

## [2.7.2] - 2026-09-02

### Fixed
- Two cron jobs (`fastmagento_cache_warmup`, `fastmagento_efficiency_scan`) failed on every install; both now run.

## [2.7.1] - 2026-09-02

### Fixed
- Grouped and bundle products served from the index reported out of stock and could not be added to the cart.

### Docs / housekeeping
- README: images under `assets/`, no links into untracked `docs/`, personalisation named as the companion package.
- `docs/` and `.planning/` kept out of the repository.

## [2.7.0] - 2026-09-02

### Added
- **Extension seams for companion modules**: a doctor check-provider pool (companions add `CheckProviderInterface` implementations; core ships none), search-stack interfaces (decorator, exploration, event recorder) with no-op defaults, `LinkProductCollectionPlugin::orderForDisplay()`, and the configurable default-values null filter. Additive only — a store without a companion behaves as 2.6.1. This is the release the FastMagento Personalisation package builds on.

### Changed
- AI prompts and docs are catalogue-generic (the vehicle-fitment framing is gone).

## [2.6.1]

### Fixed
- **System configuration page threw `The XML in file ".../etc/adminhtml/system.xml"
  is invalid` in developer mode.** The `instant_category_update` field had been
  nested inside the `<depends>` of `instant_purge_categories`. Default and
  production mode parse it silently, so it only surfaced for developers.
- **Add to cart crashed with `ProductRepository::prepareSku(): Argument #1 ($sku)
  must be of type string, null given`.** Any `ProductRepository::get($sku)` on the
  storefront hit this: core `get()` ignores the return value of `$product->load()`
  and `FrontendProductPlugin::aroundLoad` returned a shell without hydrating the
  subject. `aroundGet` now resolves the id and routes through `getById()`.

## [2.6.0] - 2026-08-20

### Performance
- Listing page ids taken from the search answer instead of re-querying for them.
- Listing special-price badges computed from the indexed prices.
- Category attribute values filled from the index instead of the EAV UNION.
- Category child lookups answered from the indexed tree.
- Served stock status built from the document instead of a query.
- The cart's absent-product check answered from the index.
- A product page no longer fetches the same review data three times; three more repeats removed from the render path.
- A served link collection reports its size instead of counting the database.
- The storefront is warmed over HTTP after a cache flush.

### Added
- A saved category is pushed into OpenSearch immediately, like products already were.

### Fixed
- The product index is refreshed after an instant update so search sees it.

## [2.5.1] - 2026-08-19

### Fixed
- Configurable price substitution is self-sufficient: no stock SQL when the registry misses.

## [2.5.0] - 2026-08-19

## What's Changed
* docs: roadmap for the Algolia-parity layer, centred on fitment-aware curation by @parkktech in https://github.com/parkktech/FastMagento/pull/14

## [2.4.1]

### Fixed
- **Related, up-sell and cross-sell blocks were never served from OpenSearch on a
  product page.** `LinkProductCollectionPlugin` gated on `$subject->getProduct()`,
  which is null there, so every link block fell straight through to the native EAV
  load — silently, with nothing failing and nothing logged. A product page cost
  ~20 product queries where a listing costs 1.

  Core only assigns `_product` in `setProduct()`; the path a product page takes
  CONSTRUCTS the collection with its root product ids instead (Hyvä's ProductList
  view model does `create(['productIds' => $productIds])`), so `_product` is never
  assigned. The parent id is now read from that constructor state via reflection
  against the declaring class — `Closure::call()` cannot reach it, because it binds
  the closure's scope to the object's `...\Interceptor` subclass, which by
  definition cannot read a private parent property, and returns null with no error.

  Falls back to the native load when the collection's link field is not
  `entity_id` (Commerce with staging uses `row_id`, while
  `catalog_product_link.product_id` is an entity_id, and translating that here
  would not be safe).

### Performance
`/chaz-kangeroo-hoodie.html`, product queries (`catalog_product_entity`, price
index, stock status, super/relation):

| | cold | warm |
|---|---|---|
| before | 20 | 4 |
| after | **3** | **2** |

Total queries cold 140 → 112. Rendered output unchanged.

## [2.4.0]

### Fixed
- **Configurable swatches did nothing when clicked** on category and product pages.
  An OpenSearch-hydrated product returned `getId()` as an int where a natively
  loaded one returns a PDO string, and `ConfigurableProduct\Helper\Data::getOptions()`
  writes that id into the jsonConfig both verbatim and as an array key. The payload
  therefore carried ints in `options[...]` against strings in `index`, and themes
  that intersect the two with a strict comparison (Hyvä's `Array.includes()`) found
  no match — so every option resolved to "unavailable" and rendered `disabled`.
  Shell ids are now normalised to strings at `setOsDoc()`, matching native parity.
- **A configurable could inherit another product's variants.** `getUsedProducts()`
  fell back to a global `child_products` registry key holding whichever configurable
  was hydrated last, so a product the shell path did not serve — a related-product
  slider that fell back to the native collection — rendered the wrong `index`,
  disabled options, and resolved a swatch click to a variant of a different product.
  The global set is now accepted only when every shell in it belongs to that parent.
- **The search grid did not match the category listing.** Column count came from a
  static setting with no relationship to the active theme, the breakpoints were
  max-width on a scale no theme shares, and the facet column was drawn inside the
  content column while the theme's own sidebar sat empty — so the grid was narrower
  than the listing at every width. Breakpoints are now min-width on the 640/768/
  1024/1280 scale, and facets hydrate the theme's sidebar.

### Added
- **Search facets render in the theme's own layered-navigation markup.** Only the
  native product list is replaced now; layered navigation stays so the theme renders
  its wrapper, heading, collapsible groups and mobile toggle, and the storefront JS
  refills them from the OpenSearch aggregation — which is far richer than native
  layered nav manages on a search page. No theme class is hardcoded and the module
  ships no styling for it, so Hyvä, Luma and Breeze each get their own look.
  Filtering stays client-side, so as-you-type search and the facet settings still work.
- **Applied search filters are visible and removable**, through the theme's own
  "currently filtering by" block rendered via `Magento_LayeredNavigation::layer/state.phtml`
  and the theme fallback. Remove and "Clear All" links are real results URLs, so one
  handler covers every case and they still work with JS off.
- **Doctor: user-defined attribute cache.** Magento re-reads every user-defined
  attribute from the database on each request unless
  `dev/caching/cache_user_defined_attributes` is on, and it ships off. Once products
  come from OpenSearch this is the largest remaining cost on a listing — two queries
  per filterable attribute, warm cache included. Measured on a 21-attribute
  catalogue: 41 of 81 warm listing queries.
- **Doctor: checkout fallback theme static content.** `Hyva_ThemeFallback` swaps the
  design theme at runtime for `/checkout/index`, so checkout renders in a different
  theme that needs its own deployed static content. When it is missing the checkout
  returns HTTP 200 with every asset 404ing underneath — including RequireJS, so the
  Knockout checkout never boots. It reads as a broken module; it is a deploy gap.

### Changed
- **Listings no longer re-fetch the category they already have.** The layered-nav
  category filter re-loaded the page's own category (four queries) despite the
  controller having loaded and registered it; `getChildrenCategories()` and
  `hasChildren()` each ran twice per render with nothing caching them. Category
  queries on a listing drop from 17 to 10, with rendered output identical.

### Performance
Measured on `/women/tops-women.html`, warm cache, 12-product grid:

| | queries | category | product |
|---|---|---|---|
| before this release | 81 | 17 | 1 |
| after | **32** | **10** | **1** |

Cold-cache figures include ~80 `GET_LOCK`/`RELEASE_LOCK` round-trips from Magento's
cache locking, which are not data queries — worth excluding when comparing.

## [2.3.0]

### Added
- GraphQL OpenSearch serving layer. The storefront GraphQL `products` query now
  hydrates its result set from OpenSearch instead of MySQL, removing the per-item
  catalog-rule and tier-price N+1 while returning a result set identical to native
  Magento. Full-text search is routed through the same InstantSearch pipeline the
  storefront uses, so GraphQL relevance matches on-site search; price and name
  sorting are mapped accordingly.
- GraphQL layered-navigation aggregations served from OpenSearch facets, including
  a price-range facet. Aggregations are built directly from InstantSearch facet
  data, bypassing the core attribute aggregation builder so OpenSearch-native
  (non-EAV) facets no longer fatal the query.

### Notes
- Controlled by `fastmagento/graphql/os_serve_products` and
  `fastmagento/graphql/os_serve_search`, both on by default. Every GraphQL read
  falls back to native Magento on a miss or an OpenSearch outage, consistent with
  the rest of the module.
