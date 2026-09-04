# FastMagento — Installation & Setup Guide

A repeatable checklist for installing FastMagento on a Magento 2 store so the next
install goes smoothly. Written from a real 2.4.8-p2 / PHP 8.4 / OpenSearch deployment.

FastMagento is a search + fast-path pipeline: it serves catalog search, autocomplete,
category/product listings, and (optionally) fast checkout **directly from OpenSearch**
instead of the MySQL/EAV hot paths. Two things therefore MUST be healthy for it to
work: (1) the OpenSearch serving indexes, and (2) the storefront JS framework that
drives the autocomplete/search UI.

---

## 1. Prerequisites

- Magento 2.4.x (verified on 2.4.8-p2 and 2.4.9), PHP 8.3/8.4. Open Source or **Adobe Commerce**:
  the content-staging schema (`row_id`) is supported since 2.10.0; on Commerce the doctor's
  **Commerce** section tells you whether category permissions or B2B shared catalogs are active,
  which OpenSearch-served listings and search do not apply yet (keep the serving flags off on
  those store views). After upgrading to 2.10.0 run `setup:di:compile` and a full
  `indexer:reindex fastmagento_product` (attribute mapping change).
- A running **OpenSearch** (or Elasticsearch 7/8) cluster reachable from the app.
- `catalog/search/engine` set (default `opensearch` on 2.4.8). Configure host/port:
  - `catalog/search/opensearch_server_hostname` (e.g. `localhost`)
  - `catalog/search/opensearch_server_port` (e.g. `9200`)
- Verify the cluster BEFORE installing:
  ```
  curl -s localhost:9200/_cluster/health
  ```
  `status: green|yellow` is fine (single-node clusters are always `yellow` — replicas
  stay unassigned; that is normal, not an error).

---

## 2. Install & enable the module

```
# app/etc/config.php is typically gitignored — enable explicitly on each environment
bin/magento module:enable ParkkTech_FastMagento --no-interaction
bin/magento setup:upgrade --keep-generated
bin/magento setup:di:compile
bin/magento setup:static-content:deploy en_US -f
bin/magento cache:flush
```

**Load order (from `etc/module.xml`).** FastMagento sequences itself AFTER the core
search stack (`Magento_CatalogSearch`, `Magento_Elasticsearch`, `Magento_OpenSearch`,
`Magento_AdvancedSearch`, `Magento_Search`, `Magento_Catalog`) and after common
third-party search modules (`Amasty_Xsearch`, `Mirasvit_Search`, `Mageworx_Search`,
`Algolia_AlgoliaSearch`) so it can override them. If another search extension is
active, expect FastMagento to take over the search routes — disable the other
extension's front-end takeover to avoid conflicts.

**Composer note (gotcha from this install):** the paid `ci.swissuplabs.com` repo
returns HTTP 401 without a license and will **block `composer install` on deploy**.
Breeze (`swissup/module-breeze`) installs fine from Packagist, so remove the paid repo
from `composer.json` (or provide valid `auth.json` credentials) before deploying.

---

## 3. Indexers — reindex ALL FOUR (this is easy to miss)

FastMagento registers four indexers (`etc/indexer.xml`). They build the OpenSearch
serving documents. A deploy that only reindexes product+category leaves attribute
option labels stale → facet labels silently break.

```
bin/magento indexer:reindex fastmagento_product
bin/magento indexer:reindex fastmagento_category
bin/magento indexer:reindex fastmagento_attribute_option   # <-- do NOT forget this one
bin/magento indexer:reindex fastmagento_review             # product reviews (2.8+)
bin/magento indexer:reindex catalogsearch_fulltext         # native, still required
```

Confirm all are `Ready`:
```
bin/magento indexer:status | grep fastmagento
```

**Ongoing maintenance — put them on cron (Update by Schedule).** The initial build
above is a one-time manual full reindex; that is expected. For a production store, set
the three indexers to **Update by Schedule** so Magento's mview changelog + cron
(`indexer_update_all_views`, "index" group) keep them fresh incrementally instead of
reindexing on every single admin save. The module ships `etc/mview.xml` with the table
subscriptions for exactly this.
```
bin/magento indexer:set-mode schedule fastmagento_product fastmagento_category fastmagento_attribute_option
bin/magento indexer:status fastmagento_product   # -> Update On: Schedule, "idle (0 in backlog)"
```
Prerequisite: a real Magento cron must be running all groups (crontab:
`* * * * * php bin/magento cron:run`). Verify the index group actually executes before
relying on schedule mode — `cron_schedule` should show recent `success` rows for
`indexer_update_all_views`; otherwise scheduled indexes silently go stale.

The resulting OpenSearch indexes (prefix = `catalog/search` prefix + FastMagento
suffix, e.g. `magento2_` + `products`):

| Index | Source indexer | Should have docs |
|-------|----------------|------------------|
| `magento2_products` | `fastmagento_product` | = # searchable products |
| `magento2_categories` | `fastmagento_category` | = # anchor categories |
| `magento2_attribute_options` | `fastmagento_attribute_option` | = # filterable option values |

```
curl -s "localhost:9200/_cat/indices?v&h=index,docs.count" | grep -E 'products|categories|attribute_options'
```

---

## 4. Front-end JS framework — Breeze compatibility (**the gotcha that broke search here**)

FastMagento's storefront JS (`view/frontend/web/js/autocomplete.js`,
`instant-search.js`) is **plain jQuery**, bootstrapped via `text/x-magento-init` /
`data-mage-init` in `default.xml` on every page. It has NO Knockout / jQuery-UI /
`mage/*` widget deps, so it runs on **both** a native Luma/RequireJS storefront **and**
a Breeze storefront (Breeze "Better Compatibility" mode reuses the same files — see
`view/frontend/layout/breeze_default.xml`).

**The trap:** if the active THEME is a Breeze theme (e.g. `Hos/diy`), the whole
storefront JS layer only works when **Breeze is actually running**. If Breeze is
installed but not active, the page falls back to RequireJS in a broken hybrid state and
the search UI dies — autocomplete never initializes and the results page JS breaks —
**even though the server endpoints return perfectly valid JSON.**

Diagnose:
```
# Server side healthy? (should be 200 + JSON both times)
curl -s "https://SITE/fastmagento/search/instant?q=test" -o /dev/null -w '%{http_code}\n'
curl -s "https://SITE/fastmagento/search/suggest?q=test" -o /dev/null -w '%{http_code}\n'

# Is Breeze actually rendered on the storefront? View source of the homepage:
#   - Breeze active  -> breeze JS bundle present, little/no requirejs
#   - Breeze OFF     -> requirejs everywhere  ==> THIS is the broken state
```

**Fix — force Breeze on.** Breeze's enable flag is `design/breeze/enabled`, default
`theme` (auto-enable only for themes Breeze recognizes). **Custom themes are often not
auto-detected**, so set it explicitly:
```
bin/magento config:set design/breeze/enabled 1     # 1 = force on (vs 'theme' auto-detect, 0 = off)
bin/magento cache:flush
```
Then re-verify the storefront source shows Breeze, and that the header search box shows
the autocomplete dropdown.

**CSP:** if a Content-Security-Policy module is enforcing (this store runs `Jadog_CSP`),
make sure the inline search init and the `fetch()` to `/fastmagento/search/instant` are
allowed. A `200` from `curl` does not prove the browser is allowed to make the call —
check the browser console for CSP violations if autocomplete is still silent.

---

## 5. Configuration (defaults are in `etc/config.xml`)

Most defaults are production-ready; two need validation per store.

- **Facet attributes** — `fastmagento/search/facet_attributes`
  (default `part_type,color,size,link_style,shock_spacing`). Each MUST exist as a
  **SELECT-type, filterable** attribute or that facet is silently dropped. Multi-select
  attributes are intentionally excluded. **Update this list to match the store's
  catalog.**
- **Fast Checkout** — `fastmagento/cart/enable_fast_checkout` (default **ON**). Master
  toggle for the whole fast cart/stock pipeline; safe by default (falls back to native
  per SKU, cannot oversell). Advanced sub-toggles default off by design.
- Search behavior — `typo_tolerance` (1), `boost_in_stock` (1), `phrase_match_boost`
  (4), `search_operator` (`any`). Good defaults; tune for precision if needed.
- **Synonyms** — a large default group list ships in config. Keep group terms
  DISTINCTIVE; put buyer phrases containing common words in the AI keyword layer
  instead (§6).
- **Footer credit** — `fastmagento/credit/show` (default on).

---

## 6. Optional: AI search-keyword layer (OFF by default)

A per-product keyword layer (`fm_search_keywords` table) that boosts recall for
buyer-phrase queries. **Not part of a normal deploy** and off until a store opts in.

```
bin/magento config:set fastmagento/search/search_keywords_enabled 1
bin/magento fastmagento:search-keywords:generate      # populates fm_search_keywords (uses Claude API)
bin/magento indexer:reindex catalogsearch_fulltext
bin/magento cache:flush
```
Requires a Claude API key configured for the store; model/limits at
`fastmagento/ai/claude_model` (`claude-opus-4-8`) and `fastmagento/ai/max_terms` (1200).
The `fm_search_keywords` table does not exist until the generator has run at least once
— that is expected, not a broken install.

---

## 7. Cron

**Build the indexes before you enable serving.** A fresh install leaves the four indexers
invalid. Either run the build yourself — `bin/magento indexer:reindex fastmagento_product
fastmagento_category fastmagento_attribute_option fastmagento_review` (in a screen/tmux session
on a large catalogue: it runs for hours there) — or let Magento's `indexer_reindex_all_invalid`
cron job pick them up on its next run, which then does that same full build inside the cron
process. Both work; just know which one you chose.

`etc/crontab.xml` adds: a cache-warmup job (hourly), an existing maintenance job (every
30 min), and an efficiency scan (config-gated by `fastmagento/efficiency/cron_enabled`,
**off** by default). Magento cron must be running for these.

> Unrelated but observed on this box: `system.log` fills every minute with
> `There are no commands defined in the "setup:cron" namespace.` That is a Magento cron
> misconfiguration (a `setup:cron`-style job scheduled without the setup CLI present),
> not FastMagento — fix it separately so real cron errors aren't buried.

---

## 8. Post-install verification checklist

- [ ] `bin/magento indexer:status` — all 4 `fastmagento_*` indexers `Ready`.
- [ ] `_cat/indices` — `magento2_products` / `magento2_categories` /
      `magento2_attribute_options` all populated.
- [ ] `curl /fastmagento/search/instant?q=<term>` → `200` + JSON with `products`.
- [ ] `curl /fastmagento/search/suggest?q=<term>` → `200` + JSON.
- [ ] Storefront HTML source shows the expected JS framework active (Breeze if a Breeze
      theme — **not** RequireJS).
- [ ] Header search box shows a live autocomplete dropdown (browser, no CSP violations).
- [ ] `/catalogsearch/result/?q=<term>` renders results with working facets.
- [ ] Facet attributes in config all exist as SELECT + filterable.

---

## 9. Deploying an update (and why the storefront can 500 for a minute if you skip this)

After any `composer require` / `composer update` / `composer remove` on a production box:

```
bin/magento setup:di:compile
bin/magento setup:upgrade --keep-generated
bin/magento cache:flush
# then reload PHP-FPM (or call opcache_reset()). With opcache.validate_timestamps=0, or a long
# opcache.revalidate_freq, the running workers keep the OLD composer autoload and will require
# files that no longer exist -> HTTP 500 -> Varnish marks the backend sick for its probe window.
sudo systemctl reload php8.x-fpm
```

## 10. Uninstall

```
bin/magento module:uninstall --remove-data ParkkTech_FastMagentoPersonalization ParkkTech_FastMagentoCheckout ParkkTech_FastMagento
```

`--remove-data` runs this module's `Setup/Uninstall`: it deletes the four OpenSearch serving
indices, the mview changelog tables, indexer/mview state, cron schedule rows and every
`fastmagento/*` setting (companions cannot run without the core, so their settings go too).
Magento then tries to `composer remove` the packages with its own embedded composer, which has
no `auth.json`; on a project with private or VCS repositories that step fails with
"Could not authenticate against github.com". If it does, finish by hand:

```
composer remove parkktech/fastmagento-personalization parkktech/fastmagento-checkout parkktech/fastmagento
bin/magento setup:di:compile && bin/magento cache:flush
sudo systemctl reload php8.x-fpm     # see section 9
```

## 11. Multi-store note

Search indexes are per store view. A website with **no products assigned** produces an
empty native search index for its store — expected, not a bug. (On this install,
website 1 `offroad` has the full catalog; website 2 `musclecar` currently has 0 products
assigned, so its native per-store search index is empty by design.)
