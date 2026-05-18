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
        // Bad: user input concatenated directly inside query() call — SQL injection risk
        return $connection->query("SELECT entity_id, sku FROM catalog_product_entity WHERE name LIKE '%" . $name . "%'")->fetchAll();
    }

    public function findBySku(string $sku): ?array
    {
        $connection = $this->getConnection();
        $stmt = $connection->prepare("SELECT * FROM catalog_product_entity WHERE sku = '" . $sku . "'");
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }
}
