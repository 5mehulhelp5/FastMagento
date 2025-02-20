<?php
namespace ParkkTech\FastMagento\Model\OpenSearch;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Elasticsearch\Model\Config as BaseOpensearchConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Search\EngineResolverInterface;
use ParkkTech\FastMagento\Helper\OpensearchConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Override the default OpenSearch Config to pull from our new helper.
 */
class Config extends BaseOpensearchConfig
{
    /**
     * Default Elasticsearch server timeout
     */
    const ELASTICSEARCH_DEFAULT_TIMEOUT = 15;

    /**
     * @var ScopeConfigInterface
     * @since 100.1.0
     */
    protected $scopeConfig;

    /**
     * @var string
     */
    private $prefix;

    /**
     * @var ClientResolver
     */
    private $clientResolver;

    /**
     * @var EngineResolverInterface
     */
    private $engineResolver;

    /**
     * @param StoreManagerInterface $storeManager
     * @param OpensearchConfig $opensearchConfig
     * @param array $data
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        OpensearchConfig $opensearchConfig,
        array $data = []
    ) {


        $this->opensearchConfig = $opensearchConfig;
    }

    /**
     * Override getClientOptions() so that the official SearchClient
     * sees our custom config (hostname, port, prefix, etc.).
     */
    public function getClientOptions($storeId = null): array
    {
        // Start with the defaults from parent
        $options = parent::getClientOptions($storeId);

        // Check if "opensearch" is actually enabled
        if (!$this->opensearchConfig->isOpensearchEnabled($storeId)) {
            // If user didn't select "opensearch" engine, let's just return parent's config
            return $options;
        }

        // Hostname
        $hostname = $this->opensearchConfig->getHostname($storeId);
        if ($hostname) {
            $options['hostname'] = $hostname;
        }

        // Port
        $port = $this->opensearchConfig->getPort($storeId);
        if ($port) {
            $options['port'] = $port;
        }

        // Index Prefix
        $prefix = $this->opensearchConfig->getIndexPrefix($storeId);
        if ($prefix) {
            $options['index_prefix'] = $prefix;
        }

        // Auth
        $enableAuth = $this->opensearchConfig->isAuthEnabled($storeId);
        $options['enableAuth'] = $enableAuth ? 1 : 0;

        if ($enableAuth) {
            $username = $this->opensearchConfig->getUsername($storeId);
            $password = $this->opensearchConfig->getPassword($storeId);
            if ($username) {
                $options['username'] = $username;
            }
            if ($password) {
                $options['password'] = $password;
            }
        }

        // Timeout
        $timeout = $this->opensearchConfig->getTimeout($storeId);
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }

        // Minimum Should Match
        $msm = $this->opensearchConfig->getMinimumShouldMatch($storeId);
        if ($msm) {
            $options['minimum_should_match'] = $msm;
        }

        return $options;
    }
}
