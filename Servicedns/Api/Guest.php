<?php
/**
 * FOSSBilling-DNS module
 *
 * Written in 2024–2025 by Taras Kondratyuk (https://namingo.org)
 * Based on example modules and inspired by existing modules of FOSSBilling
 * (https://www.fossbilling.org) and BoxBilling.
 *
 * @license Apache-2.0
 * @see https://www.apache.org/licenses/LICENSE-2.0
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