<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Laminas\Http\Client;
use Laminas\Uri\Http as HttpUri;

// Good: using Laminas\ namespace (Zend Framework successor)
class ModernHttpClient
{
    public function fetch(string $url): string
    {
        $client = new Client();
        $client->setUri(new HttpUri($url));
        return $client->send()->getBody();
    }
}
