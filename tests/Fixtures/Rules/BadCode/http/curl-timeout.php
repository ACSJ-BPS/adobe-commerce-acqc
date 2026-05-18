<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: curl_exec called without setting CURLOPT_TIMEOUT — can hang indefinitely
class ApiClient
{
    public function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        return (string) $result;
    }
}
