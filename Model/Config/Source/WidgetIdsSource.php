<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use ParkkTech\FastMagento\Model\Plp\CategoryWidgetServer;

class WidgetIdsSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => CategoryWidgetServer::IDS_FROM_SQL, 'label' => __('Database (identical order, one id query)')],
            ['value' => CategoryWidgetServer::IDS_FROM_INDEX, 'label' => __('Search index (no database; ties ordered by product id)')],
        ];
    }
}
