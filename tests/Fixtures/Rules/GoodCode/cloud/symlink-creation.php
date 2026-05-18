<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Catalog\Helper\Image;

// Good: use Magento image helper for resizing instead of manual symlinks
class AssetLinker
{
    public function __construct(private readonly Image $imageHelper)
    {
    }

    public function getThumbnailUrl(\Magento\Catalog\Api\Data\ProductInterface $product): string
    {
        return $this->imageHelper
            ->init($product, 'product_thumbnail_image')
            ->setImageFile($product->getSmallImage())
            ->resize(75, 75)
            ->getUrl();
    }
}
