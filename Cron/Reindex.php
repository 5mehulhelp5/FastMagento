<?php

namespace ParkkTech\FastMagento\Cron;

use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;
use Psr\Log\LoggerInterface;

class Reindex
{
    private $indexer;
    private $logger;

    public function __construct(ProductIndexer $indexer, LoggerInterface $logger)
    {
        $this->indexer = $indexer;
        $this->logger = $logger;
    }

    public function execute()
    {
        $this->logger->info('Running FastMagento OpenSearch Reindex...');
        $this->indexer->executeFull();
    }
}
