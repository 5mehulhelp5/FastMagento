<?php
namespace ParkkTech\FastMagento\Controller\Product;

use Magento\Catalog\Controller\Product\View as MagentoView;
use Magento\Framework\App\Action\Context;
use Magento\Catalog\Helper\Product\View as ViewHelper;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\Json\Helper\Data;
use Magento\Catalog\Model\Design;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry;
use ParkkTech\FastMagento\Helper\OpenSearchPdpFetcher;
use ParkkTech\FastMagento\Helper\ShellProductBuilder;

/**
 * Custom PDP controller that fetches doc from OpenSearch,
 * builds a no-EAV Magento\Catalog\Model\Product subclass,
 * and registers it, so Swissup/SEO sees a real product object.
 */
class View extends MagentoView
{
    protected $registry;
    protected $pdpFetcher;
    protected $shellProductBuilder;

    public function __construct(
        Context $context,
        ViewHelper $viewHelper,                // must be arg #2
        ForwardFactory $resultForwardFactory,  // must be arg #3
        PageFactory $resultPageFactory,        // must be arg #4
        LoggerInterface $logger = null,
        Data $jsonHelper = null,
        Design $catalogDesign = null,
        ProductRepositoryInterface $productRepository = null,
        StoreManagerInterface $storeManager = null,
        // Additional dependencies:
        Registry $registry,
        OpenSearchPdpFetcher $pdpFetcher,
        ShellProductBuilder $shellProductBuilder
    ) {
        parent::__construct(
            $context,
            $viewHelper,
            $resultForwardFactory,
            $resultPageFactory,
            $logger,
            $jsonHelper,
            $catalogDesign,
            $productRepository,
            $storeManager
        );
        $this->registry = $registry;
        $this->pdpFetcher = $pdpFetcher;
        $this->shellProductBuilder = $shellProductBuilder;
    }

    /**
     * Overridden `execute` method:
     * 1) get productId param
     * 2) fetch doc from OS
     * 3) build a ShellNoEavProduct (real \Magento\Catalog\Model\Product sublcass, no EAV load)
     * 4) register it and render
     */
    public function execute()
    {
        // 1) Get productId from request
        $productId = (int)$this->getRequest()->getParam('id');
        if (!$productId) {
            throw new NotFoundException(__('No product ID param.'));
        }

        // 2) Fetch doc from OpenSearch
        $doc = $this->pdpFetcher->fetchPdpById($productId);
        if (!$doc) {
            return $this->resultForwardFactory->create()->forward('noroute');
        }

        // 3) Build a "ShellNoEavProduct" from OpenSearch
        $noEavProduct = $this->shellProductBuilder->buildNoEavProductFromOsDoc($doc);

        // ✅ Ensure the product is properly registered in the registry
        if ($this->registry->registry('current_product')) {
            $this->registry->unregister('current_product');
        }
        if ($this->registry->registry('product')) {
            $this->registry->unregister('product');
        }
        $this->registry->register('current_product', $noEavProduct);
        $this->registry->register('product', $noEavProduct);
        $this->registry->register('fastmagento_pdp_doc', $doc);

        // ✅ Ensure the theme doesn't attempt to load an ORM-based product
        $this->_eventManager->dispatch('fastmagento_pdp_load_after', ['product' => $noEavProduct]);

        // ✅ Build a page result
        /** @var Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $title = $doc['name'] ?? 'Unknown product';
        $resultPage->getConfig()->getTitle()->set($title);

        return $resultPage;
    }

}
