<?php

declare(strict_types=1);

namespace Vendor\Module\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;

class Index implements HttpGetActionInterface
{
    public function execute(): void
    {
        // Bad: reading from superglobals directly bypasses Magento request abstraction
        $productId = $_GET['product_id'];
        $formData  = $_POST['form_data'];
        $search    = $_REQUEST['q'];
    }
}
