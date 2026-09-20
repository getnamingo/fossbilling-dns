<?php

// Run from an installed FOSSBilling tree after replacing the module files.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this upgrade from the command line.');
}
require dirname(__DIR__, 2) . '/load.php';
$di = include PATH_ROOT . '/di.php';
$di['translate']();
$service = $di['mod_service']('servicedns');
$service->update(json_decode(file_get_contents(__DIR__ . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR));
echo "DNS module schema and private configuration migration completed.\n";
