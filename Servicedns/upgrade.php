<?php

/*
 * FOSSBilling-DNS module
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the Apache License, Version 2.0.
 * May contain portions of FOSSBilling sample modules, also licensed under Apache-2.0.
 * See the LICENSE file for the full license text.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

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
