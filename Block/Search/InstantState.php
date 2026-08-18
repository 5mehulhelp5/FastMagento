<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Block\Search;

use Magento\Framework\DataObject;
use Magento\LayeredNavigation\Block\Navigation\State;

/**
 * The "currently filtering by" block for the instant-search results page.
 *
 * Extends core's State purely to inherit its template binding, so this renders
 * `Magento_LayeredNavigation::layer/state.phtml` THROUGH THE THEME FALLBACK — Hyva's version on a
 * Hyva store, Luma's on a Luma one. That is the whole point: the extension ships no markup and no
 * styling for the active-filter chips, remove buttons or "Clear All", and still looks native
 * everywhere.
 *
 * Core populates the block from the catalog layer, which knows nothing about our filters: the
 * instant grid filters client-side against OpenSearch, so no layer filter is ever applied. We
 * therefore feed the same shape the template consumes — an item per applied option exposing
 * getName() / getLabel() / getRemoveUrl(), and a layer stand-in whose state reports those items so
 * the template's "Clear All" branch fires.
 *
 * Rendered on every instant-search response rather than once per page load, because the applied
 * set changes without a page load. See Controller\Search\Instant.
 */
class InstantState extends State
{
    /**
     * Active filters, newest last, as the theme's state template expects.
     *
     * A plain DataObject is enough: the template only calls getName(), getLabel() and
     * getRemoveUrl() on each one, all of which magic getters answer.
     *
     * @return DataObject[]
     */
    public function getActiveFilters()
    {
        $items = $this->getData('active_filters');

        return is_array($items) ? $items : [];
    }

    /**
     * Stand-in for the catalog layer so `$block->getLayer()->getState()->getFilters()` — the
     * template's test for whether to offer "Clear All" — sees our filters instead of the layer's
     * (always empty) ones.
     *
     * @return DataObject
     */
    public function getLayer()
    {
        return new DataObject([
            'state' => new DataObject(['filters' => $this->getActiveFilters()]),
        ]);
    }

    /**
     * URL that drops every applied filter, keeping the query term.
     *
     * @return string
     */
    public function getClearUrl()
    {
        return (string) $this->getData('clear_url');
    }
}
