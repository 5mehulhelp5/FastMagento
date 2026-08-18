<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Block\Search;

use Magento\Framework\View\Element\Template;
use ParkkTech\FastMagento\Model\Search\RelevanceConfig;

/**
 * Backing block for the instant-search results grid (catalogsearch_result_index). The grid itself
 * is rendered client-side by js/fastmagento.js; this block only exposes the configured column
 * count so the grid can match the storefront's category grid (see fastmagento/search/grid_columns)
 * instead of a CSS-hard-coded count.
 */
class InstantResults extends Template
{
    public function __construct(
        Template\Context $context,
        private readonly RelevanceConfig $relevanceConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Desktop column count (1..6). Tablet/mobile counts derive from this in the template.
     */
    public function getGridColumns(): int
    {
        return $this->relevanceConfig->getGridColumns();
    }
}
