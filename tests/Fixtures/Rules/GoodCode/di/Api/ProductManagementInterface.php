<?php

declare(strict_types=1);

namespace Vendor\Module\Api;

/**
 * @api
 * Data transfer object for product price updates.
 * This file intentionally contains no interface declaration
 * to demonstrate a compliant Api/ file with proper documentation.
 */
abstract class AbstractProductData
{
    abstract public function getProductId(): int;

    abstract public function getPrice(): float;
}
