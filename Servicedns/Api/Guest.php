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

namespace Box\Mod\Servicedns\Api;

class Guest extends \FOSSBilling\Api\AbstractApi
{
    /** Return only public nameservers for an enabled DNS product. */
    public function nameservers($data): array
    {
        if (empty($data['product_id'])) throw new \FOSSBilling\InformationException('Product ID is required.');
        $product = $this->di['mod_service']('product')->findOneActiveById((int)$data['product_id']);
        $type = $product && method_exists($product, 'getType') ? $product->getType() : ($product->type ?? null);
        if (!$product || $type !== 'dns') throw new \FOSSBilling\InformationException('DNS product not found.');
        return $this->getService()->getNameservers($product);
    }
}