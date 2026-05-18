<?php

declare(strict_types=1);

namespace Vendor\Module\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

// Good: use Zend_Db_Select with the framework abstraction for counted queries
class OrderStats extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('sales_order', 'entity_id');
    }

    public function getOrderCount(int $storeId): int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from('sales_order', [new \Zend_Db_Expr('COUNT(entity_id)')])
            ->where('store_id = ?', $storeId);
        return (int) $connection->fetchOne($select);
    }
}
