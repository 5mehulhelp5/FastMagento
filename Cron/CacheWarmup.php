<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Cron;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpenSearchConfig;
use Psr\Log\LoggerInterface;

/**
 * Hourly: run the merchant's popular searches against the product index so their result sets
 * are warm in OpenSearch's caches before shoppers ask.
 *
 * The client is resolved through Magento's ClientResolver at run time, the same way every other
 * read path in this module gets it. Injecting OpenSearch\Client directly is not constructible
 * by the object manager (its transport is a required argument), which is why this job used to
 * fail on every schedule with "Missing required argument $transport".
 */
class CacheWarmup
{
    public const XML_PATH_POPULAR_SEARCHES = 'fastmagento/cache/popular_searches';

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly EngineResolverInterface $engineResolver,
        private readonly OpenSearchConfig $openSearchConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $configured = (string) ($this->scopeConfig->getValue(self::XML_PATH_POPULAR_SEARCHES) ?? '');
        $terms = array_values(array_filter(array_map('trim', explode(',', $configured))));
        if (!$terms) {
            return;
        }

        try {
            $client = $this->clientResolver
                ->create($this->engineResolver->getCurrentSearchEngine())
                ->getOpenSearchClient();
        } catch (\Throwable $e) {
            $this->logger->warning('FastMagento cache warmup skipped: ' . $e->getMessage());
            return;
        }

        $index = $this->openSearchConfig->getIndexName();
        $warmed = 0;
        foreach ($terms as $term) {
            try {
                $client->search([
                    'index' => $index,
                    'body' => [
                        'query' => ['bool' => ['must' => [['match' => ['name' => $term]]]]],
                        'size' => 50,
                    ],
                ]);
                $warmed++;
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('FastMagento cache warmup: "%s" failed: %s', $term, $e->getMessage()));
            }
        }
        $this->logger->info(sprintf('FastMagento cache warmup: %d of %d popular search(es) warmed.', $warmed, count($terms)));
    }
}
