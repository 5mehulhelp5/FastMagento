<?php

namespace ParkkTech\FastMagento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

/**
 * Reads Magento's search/engine settings,
 * including 'OpenSearch' or 'Elasticsearch7' config keys.
 */
class SearchConfig extends AbstractHelper
{
    /**
     * Core engine selection: 'opensearch' or 'elasticsearch7'
     */
    const XML_PATH_SEARCH_ENGINE = 'catalog/search/engine';

    /**
     * Index prefix under "elasticsearch7_index_prefix",
     * used for OpenSearch or ES7 in Magento 2.4.7
     */
    const XML_PATH_INDEX_PREFIX = 'catalog/search/elasticsearch7_index_prefix';

    /**
     * Comma-separated search attributes from config
     */
    const XML_PATH_SEARCH_ATTRIBUTES = 'catalog/search/search_attributes';

    /**
     * Comma-separated sorting priorities from config
     */
    const XML_PATH_SORTING_PRIORITIES = 'catalog/search/sorting_priorities';

    /**
     * Hostname for ES7/OpenSearch
     */
    const XML_PATH_ES_HOSTNAME = 'catalog/search/elasticsearch7_server_hostname';

    /**
     * Port for ES7/OpenSearch
     */
    const XML_PATH_ES_PORT = 'catalog/search/elasticsearch7_server_port';

    /**
     * Constructor.
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * Returns which search engine is set, e.g. 'opensearch' or 'elasticsearch7'
     */
    public function getSearchEngine(): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_SEARCH_ENGINE);
    }

    /**
     * Returns the index prefix (e.g. 'hr_prod_local'),
     * stored in 'catalog/search/elasticsearch7_index_prefix'.
     */
    public function getIndexPrefix(): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_INDEX_PREFIX);
    }

    /**
     * Returns the search attributes array,
     * from 'catalog/search/search_attributes'.
     */
    public function getSearchAttributes(): array
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SEARCH_ATTRIBUTES);
        return $value ? explode(',', $value) : [];
    }

    /**
     * Returns the sorting priorities array,
     * from 'catalog/search/sorting_priorities'.
     */
    public function getSortingPriorities(): array
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SORTING_PRIORITIES);
        return $value ? explode(',', $value) : [];
    }

    /**
     * Returns the OpenSearch/ES7 hostname (e.g. 'localhost'),
     * from 'catalog/search/elasticsearch7_server_hostname'.
     */
    public function getHostname(): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_ES_HOSTNAME);
    }

    /**
     * Returns the OpenSearch/ES7 port (e.g. '9200'),
     * from 'catalog/search/elasticsearch7_server_port'.
     */
    public function getPort(): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_ES_PORT);
    }
}
