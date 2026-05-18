<?php

declare(strict_types=1);

namespace Vendor\Module\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

// Bad: SELECT COUNT(*) in application code — unoptimized counting
class OrderStats extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('sales_order', 'entity_id');
    }

    public function getOrderCount(int $storeId): int
    {
        $connection = $this->getConnection();
        $sql        = "SELECT COUNT(*) FROM sales_order WHERE store_id = ?";
        return (int) $connection->fetchOne($sql, [$storeId]);
    }
}
