<?php

// This helper must never bootstrap Laravel or receive subscription credentials.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Services\WebPush\DeliveryException;
use App\Services\WebPush\DnsLookup;
use App\Services\WebPush\EndpointPolicy;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function () {
    throw new RuntimeException('resolver_error');
});

try {
    $host = (new EndpointPolicy)->hostname($argv[1] ?? '');
    $addresses = (new DnsLookup)->lookup($host);
    echo json_encode(['addresses' => $addresses], JSON_THROW_ON_ERROR);
} catch (DeliveryException $e) {
    echo json_encode(['error' => $e->category], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo '{"error":"dns_error"}';
}
