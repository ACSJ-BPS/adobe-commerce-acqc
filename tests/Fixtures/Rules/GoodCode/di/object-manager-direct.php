<?php

declare(strict_types=1);

namespace Vendor\Module\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\Result\Page;

class Index implements HttpGetActionInterface
{
    public function __construct(private readonly PageFactory $pageFactory)
    {
    }

    public function execute(): Page
    {
        // Good: dependency injected via constructor, no ObjectManager usage
        return $this->pageFactory->create();
    }
}
