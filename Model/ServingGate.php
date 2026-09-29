<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Master switch for FastMagento's storefront serving layer.
 *
 * When off, every read hook hands straight back to stock Magento: nothing is served from the
 * FastMagento indices and no core behaviour is replaced. Index writes (indexers, stock and price
 * sync, reindex-on-save) keep running, so the indices stay current and serving can be switched
 * back on instantly. Flip it with
 *   bin/magento config:set fastmagento/general/serving_enabled 0 && bin/magento cache:flush
 * No recompile or static deploy is needed.
 *
 * The switch only applies to storefront areas (frontend, GraphQL, REST/SOAP). Admin, cron and CLI
 * always see it as on, because their reads of the indices serve index maintenance (e.g. finding a
 * saved category's descendants to update), not shoppers.
 */
class ServingGate
{
    public const XML_PATH = 'fastmagento/general/serving_enabled';

    private const STOREFRONT_AREAS = [
        Area::AREA_FRONTEND,
        Area::AREA_GRAPHQL,
        Area::AREA_WEBAPI_REST,
        Area::AREA_WEBAPI_SOAP,
    ];

    /**
     * @var array<int, bool> store id => enabled, per request
     */
    private array $memo = [];

    /**
     * > 0 while index maintenance runs inside a storefront-area request (see bypass()).
     */
    private int $bypassDepth = 0;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState
    ) {
    }

    public function isEnabled(): bool
    {
        if ($this->bypassDepth > 0) {
            return true;
        }
        try {
            if (!in_array($this->appState->getAreaCode(), self::STOREFRONT_AREAS, true)) {
                return true;
            }
        } catch (\Throwable $e) {
            return true; // no area set: CLI / setup, never a storefront request
        }
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            $storeId = 0;
        }
        return $this->memo[$storeId] ??= $this->scopeConfig->isSetFlag(
            self::XML_PATH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Run index maintenance that reads the indices (e.g. a category save through the REST API
     * finding its descendants) with the switch treated as on. The switch governs what shoppers
     * are served, never whether the indices are kept current.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function bypass(callable $fn)
    {
        $this->bypassDepth++;
        try {
            return $fn();
        } finally {
            $this->bypassDepth--;
        }
    }
}
