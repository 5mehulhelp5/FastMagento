<?php

namespace ParkkTech\FastMagento\Controller\Product;

use Magento\Catalog\Controller\Product\View as MagentoView;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Catalog\Helper\Product\View as ViewHelper;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\PageFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\Json\Helper\Data;
use Magento\Catalog\Model\Design;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Catalog\Helper\Product\View as CatalogProductView;

/**
 * Custom PDP controller that fetches doc from OpenSearch,
 * builds a no-EAV Magento\Catalog\Model\Product subclass,
 * and registers it, so Swissup/SEO sees a real product object.
 */
class View extends MagentoView
{
    /**
     * The parent's logger is private; keep our own reference for the render error path.
     */
    private LoggerInterface $pdpLogger;

    /**
     * @param Context $context
     * @param CatalogProductView $viewHelper
     * @param ForwardFactory $resultForwardFactory
     * @param PageFactory $resultPageFactory
     * @param LoggerInterface|null $logger
     * @param Data|null $jsonHelper
     * @param Design|null $catalogDesign
     * @param ProductRepositoryInterface|null $productRepository
     * @param StoreManagerInterface|null $storeManager
     * @param CatalogProductView $catalogProductView
     */
    public function __construct(
        Context $context,
        ViewHelper $viewHelper,
        ForwardFactory $resultForwardFactory,
        PageFactory $resultPageFactory,
        private CatalogProductView $catalogProductView,
        ?LoggerInterface $logger = null,
        ?Data $jsonHelper = null,
        ?Design $catalogDesign = null,
        ?ProductRepositoryInterface $productRepository = null,
        ?StoreManagerInterface $storeManager = null
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
        // Optional constructor arguments are not injected by the object manager (same fallback as core).
        $this->pdpLogger = $logger ?? ObjectManager::getInstance()->get(LoggerInterface::class);
    }

    /**
     * @return \Magento\Framework\Controller\Result\Forward|\Magento\Framework\Controller\Result\Redirect|\Magento\Framework\View\Result\Page
     * @throws NotFoundException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $request = $this->getRequest();
        $productId = (int)$request->getParam('id');
        if (!$productId) {
            throw new NotFoundException(__('No product ID param.'));
        }

        // POST back to the PDP (e.g. add-to-cart that still needs options) keeps core behaviour.
        if ($request->isPost() && $request->getParam(self::PARAM_NAME_URL_ENCODED)) {
            return parent::execute();
        }

        $params = new DataObject();
        $params->setCategoryId((int)$request->getParam('category', false));
        $params->setSpecifyOptions($request->getParam('options'));

        // Same outcomes as the core controller: a product that can't be shown here (disabled,
        // not in this website, not visible, deleted) is a 404, never a 500. Core's
        // applyCustomDesign() is skipped on purpose: it re-loads the product through EAV.
        try {
            $resultPage = $this->resultPageFactory->create();
            $this->catalogProductView->prepareAndRender($resultPage, $productId, $this, $params);
            return $resultPage;
        } catch (NoSuchEntityException $e) {
            return $this->noProductRedirect();
        } catch (\Exception $e) {
            $this->pdpLogger->critical($e);
            return $this->resultForwardFactory->create()->forward('noroute');
        }
    }
}
