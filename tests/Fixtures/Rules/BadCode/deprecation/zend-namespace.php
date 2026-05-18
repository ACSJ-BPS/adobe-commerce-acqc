<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Zend\Http\Client;
use Zend\Uri\Http as HttpUri;

// Bad: using Zend\ (Zend Framework 2/3) namespace — replaced by Laminas in Magento 2.4+
class LegacyHttpClient
{
    public function fetch(string $url): string
    {
        $client = new Client();
        $client->setUri(new HttpUri($url));
        return $client->send()->getBody();
    }
}
