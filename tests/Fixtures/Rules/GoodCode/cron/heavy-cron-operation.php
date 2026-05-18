<?php

declare(strict_types=1);

namespace Vendor\Module\Cron;

use Magento\Framework\MessageQueue\PublisherInterface;

class ProductExport implements \Vendor\Module\Cron\CronInterface
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly \Vendor\Module\Model\ProductBatchProvider $batchProvider,
    ) {
    }

    public function execute(): void
    {
        // Good: publish batch job to queue, no heavy processing in the cron itself
        $offset = 0;
        $batch  = $this->batchProvider->getBatch($offset, self::BATCH_SIZE);
        if (!empty($batch)) {
            $this->publisher->publish('vendor.module.product.export', ['ids' => $batch]);
        }
    }
}
