# Theme compatibility

FastMagento's storefront JavaScript (`view/frontend/web/js/fastmagento.js`) has **no dependencies**:
no jQuery, no RequireJS, no Alpine, no Knockout. It is loaded as a plain deferred `<script>` and
boots itself from `application/json` islands rendered by the module's templates.

That means the same build runs, unchanged, on:

| Storefront | Status | Notes |
|---|---|---|
| Default Magento (Luma / Blank) | Supported | RequireJS is present but unused. |
| Hyvä | Supported | Hyvä ships neither jQuery nor RequireJS — which is exactly why the previous `text/x-magento-init` bootstrap silently never ran there. |
| Swissup Breeze | Supported | No "Better Compatibility" registration needed any more; there are no RequireJS modules left to shim. |

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
