<?php

namespace ParkkTech\FastMagento\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Search\Model\QueryFactory;
use Magento\Framework\App\RequestInterface;

class CacheWarmupPlugin
{
    private $scopeConfig;
    private $queryFactory;
    private $request;

    const XML_PATH_POPULAR_SEARCHES = 'fastmagento/cache/popular_searches';

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        QueryFactory $queryFactory,
        RequestInterface $request
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->queryFactory = $queryFactory;
        $this->request = $request;
    }

    public function afterGetQueryText(QueryFactory $subject, $result)
    {
        $searchQuery = strtolower($result);
        if (!$searchQuery) {
            return $result;
        }

        $popularSearches = explode(',', $this->scopeConfig->getValue(self::XML_PATH_POPULAR_SEARCHES));
        if (!in_array($searchQuery, $popularSearches)) {
            $popularSearches[] = $searchQuery;
            $this->scopeConfig->setValue(self::XML_PATH_POPULAR_SEARCHES, implode(',', $popularSearches));
        }

        return $result;
    }
}
