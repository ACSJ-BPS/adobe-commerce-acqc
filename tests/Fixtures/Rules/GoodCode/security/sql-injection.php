<?php

declare(strict_types=1);

namespace Vendor\Module\Model\Resource;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductSearch extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('catalog_product_entity', 'entity_id');
    }

    public function searchByName(string $name): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from('catalog_product_entity', ['entity_id', 'sku'])
            ->where('name LIKE ?', '%' . $name . '%')
            ->order('entity_id ASC');
        return $connection->fetchAll($select);
    }
}
