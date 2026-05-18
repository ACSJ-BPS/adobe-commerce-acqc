<?php

declare(strict_types=1);

namespace Vendor\Module\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;

class Index implements HttpGetActionInterface
{
    public function __construct(private readonly RequestInterface $request)
    {
    }

    public function execute(): void
    {
        // Good: use Magento request abstraction for filtered/validated input
        $productId = (int) $this->request->getParam('product_id');
        $formData  = $this->request->getPostValue('form_data');
        $search    = $this->request->getParam('q', '');
    }
}
