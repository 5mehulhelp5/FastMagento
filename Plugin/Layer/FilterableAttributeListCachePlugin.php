<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Layer;

use Magento\Catalog\Model\Layer\Category\FilterableAttributeList;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Data\CollectionFactory as DataCollectionFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The layer's filterable-attribute list from the cache.
 *
 * FilterList asks FilterableAttributeList::getList() on every listing and search page, and
 * the list is a fresh attribute-collection load each time (eav_attribute joined to
 * catalog_eav_attribute, store labels, position order) although it changes only when an
 * attribute is saved. This caches the attribute ids and store labels per store (and per
 * concrete list class — the search variant filters differently) and rebuilds the list from
 * the EAV config, which serves attribute models from its own cache without a query.
 */
class FilterableAttributeListCachePlugin
{
    public const XML_PATH_ENABLED = 'fastmagento/serving/cache_filterable_attributes';
    private const CACHE_KEY = 'FASTMAGENTO_FILTERABLE_ATTRIBUTES_';
    private const LIFETIME = 86400;

    private array $memo = [];

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly DataCollectionFactory $dataCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function aroundGetList(FilterableAttributeList $subject, callable $proceed)
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return $proceed();
        }
        $key = null;
        try {
            $key = self::CACHE_KEY . strtoupper(preg_replace('/[^a-z0-9]/i', '_', get_class($subject)))
                . '_' . (int) $this->storeManager->getStore()->getId();
            if (isset($this->memo[$key])) {
                return $this->memo[$key];
            }
            $raw = $this->cache->load($key);
            if ($raw) {
                $list = $this->hydrate($this->serializer->unserialize($raw));
                if ($list !== null) {
                    return $this->memo[$key] = $list;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[FastMagento] filterable attribute cache read failed: ' . $e->getMessage());
        }

        $native = $proceed();
        if ($key !== null) {
            try {
                $rows = [];
                foreach ($native as $attribute) {
                    $rows[] = [(int) $attribute->getId(), (string) $attribute->getData('store_label')];
                }
                $this->cache->save(
                    $this->serializer->serialize($rows),
                    $key,
                    [EavAttribute::CACHE_TAG],
                    self::LIFETIME
                );
            } catch (\Throwable $e) {
                $this->logger->debug('[FastMagento] filterable attribute cache write failed: ' . $e->getMessage());
            }
            $this->memo[$key] = $native;
        }
        return $native;
    }

    /**
     * @param array<int, array{0:int,1:string}> $rows
     */
    private function hydrate(array $rows): ?DataCollection
    {
        /** @var DataCollection $collection */
        $collection = $this->dataCollectionFactory->create();
        foreach ($rows as [$id, $storeLabel]) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, (int) $id);
            if (!$attribute || !$attribute->getId()) {
                return null;   // attribute gone since the list was cached: let the query decide
            }
            $attribute->setData('store_label', $storeLabel);
            $collection->addItem($attribute);
        }
        return $collection;
    }
}
