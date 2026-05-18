<?php

declare(strict_types=1);

namespace Vendor\Module\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index implements HttpGetActionInterface
{
    public function execute()
    {
        // Bad: direct ObjectManager usage bypasses DI
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $pageFactory   = $objectManager->get(PageFactory::class);

        return $pageFactory->create();
    }
}
