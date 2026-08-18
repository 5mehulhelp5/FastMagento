# Theme compatibility

FastMagento's storefront JavaScript (`view/frontend/web/js/fastmagento.js`) has **no dependencies**:
no jQuery, no RequireJS, no Alpine, no Knockout. It is loaded as a plain deferred `<script>` and
boots itself from `application/json` islands rendered by the module's templates.

That means the same build runs, unchanged, on:

| Storefront | Status | Notes |
|---|---|---|
| Default Magento (Luma / Blank) | Supported (verified) | RequireJS is present but unused. |
| Hyvä | Supported (verified) | Verified on Hyvä 1.5.2. Hyvä ships neither jQuery nor RequireJS — which is exactly why the previous `text/x-magento-init` bootstrap silently never ran there. |
| Swissup Breeze | Supported (verified) | Verified on `swissup/theme-frontend-breeze-blank` 2.10 + `swissup/module-breeze` 2.x. No "Better Compatibility" registration needed any more; there are no RequireJS modules left to shim. |

**None of these themes is required, and none is special-cased.**

## What changed, and why

The storefront JS used to be jQuery bootstrapped through `<script type="text/x-magento-init">` and
`data-mage-init`. Those tags are only executed by Magento's RequireJS bootstrap (`mage/apply/main`).
On a theme that does not ship RequireJS the markup rendered and nothing ever read it, so
autocomplete and instant search were inert — with no console error, because nothing had failed;
nothing had run.

The search results page made that worse than a missing feature: its layout XML unconditionally
removed `search.result` and the layered-navigation blocks and replaced them with a JS-hydrated
container. If the JS could not run, the visitor got HTTP 200 with an **empty results area**. Those
removals now happen in `Observer\ApplyInstantSearchLayout`, gated on the
`fastmagento/search/instant_search_enabled` toggle and on our own block actually being present, so
the worst case is native Magento search rather than a blank page.

## Checkout

`ParkkTech_FastMagentoCheckout` is a separate matter: it renders by extending the Luma/Knockout
`checkout.root` block, which **Hyvä's default theme does not output**. On Hyvä install the free
fallback, or the module stays inert:

```bash
composer require hyva-themes/magento2-luma-checkout
```

`bin/magento fastmagento:doctor` reports this explicitly, per store view.

## Custom themes

The search input is located by `#search`, then `input[name="q"][type="search"]`, then
`input[name="q"]`. If your theme renders something else, pass an explicit selector to the block:

```xml
<referenceBlock name="fastmagento.autocomplete.init">
    <arguments>
        <argument name="search_input_selector" xsi:type="string">.my-theme-search input</argument>
    </arguments>
</referenceBlock>
```

## Verification matrix

All three verified on the same Magento 2.4.7-p10 install with Luma sample data (2,046 products)
and OpenSearch 2.19.5, by switching only `design/theme/theme_id`:

| Storefront | Autocomplete | Instant search grid | Live faceting | Console errors |
|---|---|---|---|---|
| Hyvä 1.5.2 | ✅ | ✅ | ✅ 24 → 11 on a colour filter | 0 |
| Swissup Breeze Blank 2.10 | ✅ | ✅ | ✅ 24 → 11 on a colour filter | 0 |
| Magento Luma | ✅ | ✅ | ✅ | 0 |

Identical bundle, no per-theme branches, no compatibility layer.
