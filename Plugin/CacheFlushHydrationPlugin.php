<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use ParkkTech\FastMagento\Helper\WriteLog;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;

/**
 * Re-prime FastMagento's caches immediately after a cache flush, instead of on a shopper's request.
 *
 * WHY
 * ---
 * `cache:flush` does not just empty caches, it hands the whole rebuild bill to whoever loads the
 * next page. Measured on 2.4.9 + Luma sample data, the first category page after a flush cost 123
 * queries against 34 for the same page warm — and a chunk of that is FastMagento's own option
 * dictionary rebuilding inside the request. That is the worst possible moment to pay it: a deploy
 * or an admin "Flush Magento Cache" click is exactly when real traffic is arriving, and the cost
 * lands on a shopper rather than on the operator who caused it.
 *
 * Priming here moves the work to the process that did the flushing (CLI, or the admin request that
 * clicked the button), where it is expected to take a moment and where nobody is waiting on a
 * product grid.
 *
 * SAFETY
 * ------
 * Never allowed to break a flush. Every failure is logged and swallowed: a store whose OpenSearch
 * is down must still be able to clear its cache. Gated by fastmagento/cache/warm_after_flush so an
 * operator scripting many flushes in a row can turn it off.
 */
class CacheFlushHydrationPlugin
{
    private const XML_PATH_WARM_AFTER_FLUSH = 'fastmagento/cache/warm_after_flush';
    private const XML_PATH_WARM_PATHS = 'fastmagento/cache/warm_paths';

    /** Hard ceiling so a long warm_paths list can never turn a flush into a crawl. */
    private const MAX_WARM_URLS = 10;
    private const CONNECT_TIMEOUT = 5;
    private const REQUEST_TIMEOUT = 30;
    private const USER_AGENT = 'FastMagento-CacheWarmer';

    /** Guards against re-entry when a single command flushes several cache-type groups. */
    private bool $done = false;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly WriteLog $writeLog
    ) {
    }

    /**
     * @param Manager $subject
     * @param array $result
     * @return array
     */
    public function afterFlush(Manager $subject, $result)
    {
        $this->hydrate();

        return $result;
    }

    /**
     * @param Manager $subject
     * @param array $result
     * @return array
     */
    public function afterClean(Manager $subject, $result)
    {
        $this->hydrate();

        return $result;
    }

    private function hydrate(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;

        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_WARM_AFTER_FLUSH)) {
            return;
        }

        try {
            foreach ($this->warmUrls() as $url) {
                $this->fetch($url);
            }
        } catch (\Throwable $e) {
            // A flush must always succeed, even with the storefront down.
            $this->writeLog->writeErrorLog('[FastMagento] post-flush warm-up skipped: ' . $e->getMessage());
        }
    }

    /**
     * The URLs to request, per store view.
     *
     * Default is each store's own base URL. `fastmagento/cache/warm_paths` takes a comma-separated
     * list of paths (e.g. "/,women/tops-women.html") when a store wants a representative category
     * and product page warmed too — the caches a category page builds are largely the ones every
     * other category page then reuses.
     *
     * @return string[]
     */
    private function warmUrls(): array
    {
        $urls = [];
        $paths = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->scopeConfig->getValue(self::XML_PATH_WARM_PATHS))
        )));

        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }
            $base = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/');
            if ($base === '') {
                continue;
            }
            if (!$paths) {
                $urls[] = $base . '/';
                continue;
            }
            foreach ($paths as $path) {
                $urls[] = $base . '/' . ltrim($path, '/');
            }
        }

        return array_slice(array_unique($urls), 0, self::MAX_WARM_URLS);
    }

    /**
     * One warm-up request.
     *
     * Deliberately crude: the response is thrown away, because the point is the side effect —
     * the storefront process rebuilding its own caches, in its own area and store scope, which is
     * the only context whose cache entries it will actually read back. That is why this is an HTTP
     * request and not an in-process prime: priming from the CLI writes entries the storefront
     * never looks up (measured: a CLI hydrate left 18 files in var/cache where one real request
     * wrote 199, and the first shopper still paid every EAV query).
     *
     * Failure is never fatal and never retried — a warm-up is an optimisation, and a flush that
     * fails because the storefront was briefly unreachable would be a far worse bug than a cold
     * cache.
     */
    private function fetch(string $url): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => false,          // a HEAD would skip the block rendering we want
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,  // local/staging certs are routinely self-signed
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => self::USER_AGENT,
            // Bypass the full-page cache so the request actually rebuilds the block/EAV/config
            // caches instead of being served a stored page and warming nothing.
            CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache'],
        ]);

        $ok = curl_exec($ch);
        $err = $ok === false ? curl_error($ch) : '';
        curl_close($ch);

        if ($err !== '') {
            $this->writeLog->writeErrorLog(
                '[FastMagento] warm-up request failed for ' . $url . ': ' . $err
            );
        }
    }
}
