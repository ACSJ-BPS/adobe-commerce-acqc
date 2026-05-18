<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: using Zend_ prefixed class from Zend Framework 1 era (removed in Magento 2.4+)
class LegacyTransport
{
    public function send(array $data): void
    {
        $mail = new Zend_Mail('utf-8');
        $mail->setSubject('Order Confirmation');
        $mail->setBodyText(implode("\n", $data));
        $mail->send();
    }
}
