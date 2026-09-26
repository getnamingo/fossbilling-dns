<?php
/**
 * FOSSBilling-DNS module
 *
 * Written in 2024–2026 by Taras Kondratyuk (https://namingo.org)
 * Based on example modules and inspired by existing modules of FOSSBilling
 * (https://www.fossbilling.org) and BoxBilling.
 *
 * @license Apache-2.0
 * @see https://www.apache.org/licenses/LICENSE-2.0
 */

namespace Box\Mod\Servicedns;

defined('PLEX_TABLE_ZONES') || define('PLEX_TABLE_ZONES', 'service_dns');
defined('PLEX_TABLE_RECORDS') || define('PLEX_TABLE_RECORDS', 'service_dns_records');

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use FOSSBilling\InjectionAwareInterface;
use RedBeanPHP\OODBBean;
use PlexDNS\Service as PlexService;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function getModulePermissions(): array
    {
        return ['manage' => [
            'type' => 'bool',
            'display_name' => __trans('Manage DNS records'),
            'description' => __trans('Allows staff to add, update and delete DNS records.'),
        ]];
    }

    public function getCartProductTitle($product, array $data)
    {
        return __trans('DNS Hosting for :domain', [':domain' => $data['domain_name'] ?? '']);
    }

    public function clientSettableConfigKeys(): array
    {
        return ['domain_name', 'period', 'quantity'];
    }

    public function attachOrderConfig(\Box\Mod\Product\Entity\Product|\Model_Product $product, array $data): array
    {
        // Cart/order config is returned to clients by FOSSBilling. Never put secrets here.
        $config = $this->decodeConfig(method_exists($product, 'getConfig') ? $product->getConfig() : $product->config);
        $public = $this->publicConfig($config);
        $public['domain_name'] = $this->normalizeDomain($data['domain_name'] ?? '');
        foreach (['period', 'quantity'] as $key) {
            if (isset($data[$key])) $public[$key] = $data[$key];
        }
        return $public;
    }

    public function validateOrderData(array $data): void
    {
        $name = $this->normalizeDomain($data['domain_name'] ?? '');
        if ($this->di['db']->findOne('service_dns', 'domain_name = :name', [':name' => $name])) {
            throw new \FOSSBilling\InformationException('This DNS zone already has an order.');
        }
    }

    private function normalizeDomain($name): string
    {
        if (!is_string($name)) throw new \FOSSBilling\InformationException('A valid domain name is required.');
        $name = strtolower(rtrim(trim($name), '.'));
        if (function_exists('idn_to_ascii')) {
            $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }
        if (strlen($name) > 253 || !str_contains($name, '.') || !filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \FOSSBilling\InformationException('A valid domain name is required.');
        }
        return $name;
    }

    private function decodeConfig(?string $json): array
    {
        $config = json_decode($json ?? '', true);
        return is_array($config) ? $config : [];
    }

    private function publicConfig(array $config): array
    {
        $keys = ['domain_name', 'provider', 'period', 'quantity'];
        for ($i = 1; $i <= 13; $i++) $keys[] = 'ns' . $i;
        return array_intersect_key($config, array_flip($keys));
    }

    private function productConfig(int $productId): array
    {
        $products = $this->di['mod_service']('product');
        $product = method_exists($products, 'findProductById')
            ? $products->findProductById($productId)
            : $this->di['db']->getExistingModelById('Product', $productId);
        return $this->decodeConfig(method_exists($product, 'getConfig') ? $product->getConfig() : $product->config);
    }

    public function getNameservers($product): array
    {
        $config = $this->decodeConfig(method_exists($product, 'getConfig') ? $product->getConfig() : $product->config);
        $nameservers = [];
        for ($i = 1; $i <= 13; $i++) {
            if (!empty($config['ns' . $i]) && is_string($config['ns' . $i])) $nameservers[] = $config['ns' . $i];
        }
        return $nameservers;
    }

    public function create(\Model_ClientOrder|OODBBean $order): OODBBean
    {
        $public = $this->decodeConfig($order->config);
        $domainName = $this->normalizeDomain($public['domain_name'] ?? '');
        $this->validateOrderData(['domain_name' => $domainName]);
        // Only the administrator's product supplies connection settings.
        $config = $this->productConfig((int)$order->product_id);
        $config['domain_name'] = $domainName;
        unset($config['_provisioned']);

        $model = $this->di['db']->dispense('service_dns');
        $model->client_id = $order->client_id;
        $model->domain_name = $domainName;
        $model->config = json_encode($config, JSON_THROW_ON_ERROR);
        $model->created_at = $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        // FOSSBilling saves service_id after create() returns.
        $order->config = json_encode($this->publicConfig($config) + array_intersect_key($public, array_flip(['period', 'quantity'])), JSON_THROW_ON_ERROR);
        $order->title = 'DNS: ' . $domainName;
        $this->di['db']->store($order);
        return $model;
    }

    protected function plex(): PlexService
    {
        return new PlexService($this->di['pdo']);
    }

    private function providerConfig(OODBBean $model): array
    {
        $config = $this->decodeConfig($model->config);
        $config['domain_name'] = (string)$model->domain_name;
        if (empty($config['domain_name']) || empty($config['provider'])) {
            throw new \FOSSBilling\InformationException('DNS domain/provider configuration is missing.');
        }
        // Preserve ClouDNS authentication, SOA, nameservers and secondary server settings.
        return $config;
    }

    public function activate(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool
    {
        $config = $this->providerConfig($model);
        if (!empty($config['_provisioned'])) return true;
        $this->plex()->createDomain(['client_id' => $order->client_id, 'config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        // PlexDNS upserts the same row. Do not overwrite its new zoneId with the stale bean.
        $model->setProperty('zoneId', $this->di['db']->getCell('SELECT zoneId FROM service_dns WHERE id = :id', [':id' => $model->id]));
        $config['_provisioned'] = true;
        $model->config = json_encode($config, JSON_THROW_ON_ERROR);
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return true;
    }

    public function suspend(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool
    {
        // DNS keeps resolving; API editing is blocked by the order status check below.
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        return true;
    }

    public function unsuspend(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool { return $this->suspend($order, $model); }
    public function cancel(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool { return $this->suspend($order, $model); }
    public function uncancel(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool { return $this->unsuspend($order, $model); }
    public function renew(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool { return $this->unsuspend($order, $model); }

    public function delete(\Model_ClientOrder|OODBBean|null $order, ?OODBBean $model): void
    {
        // Deleting an unprovisioned order must never delete a zone just by its submitted name.
        if (!$model || !$model->id) return;
        $config = $this->providerConfig($model);
        if (!empty($config['_provisioned']) || ($order && !empty($order->activated_at))) {
            $this->plex()->deleteDomain(['config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        }
        // Do not swallow provider failures or treat an unrelated 404 as successful deletion.
        $this->di['db']->trash($model);
    }

    public function toApiArray(OODBBean $model, $deep = true, $identity = null): array
    {
        return [
            'id' => $model->id,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
            'domain_name' => $model->domain_name,
            'records' => $this->di['db']->getAll('SELECT id, type, host, value, ttl, priority FROM service_dns_records WHERE domain_id = :id', [':id' => $model->id]),
            'config' => $this->publicConfig($this->decodeConfig($model->config)),
        ];
    }

    private function managedZone(array $data, $identity): OODBBean
    {
        $isAdmin = $identity instanceof \Model_Admin || $identity instanceof \Box\Mod\Staff\Entity\Admin;
        $isClient = $identity instanceof \Model_Client || $identity instanceof \Box\Mod\Client\Entity\Client;
        if ((!$isAdmin && !$isClient) || empty($data['order_id'])) {
            throw new \FOSSBilling\InformationException('DNS order not found.');
        }
        $order = $this->di['db']->getExistingModelById('ClientOrder', (int)$data['order_id'], 'DNS order not found.');
        $identityId = method_exists($identity, 'getId') ? $identity->getId() : $identity->id;
        if ($order->service_type !== 'dns' || ($isClient && (int)$order->client_id !== (int)$identityId)) {
            throw new \FOSSBilling\InformationException('DNS order not found.');
        }
        if ($order->status !== 'active') {
            throw new \FOSSBilling\InformationException('DNS order is not active.');
        }
        $model = $this->di['mod_service']('order')->getOrderService($order);
        if (!$model instanceof OODBBean || !$model->id || (int)$model->client_id !== (int)$order->client_id) {
            throw new \FOSSBilling\InformationException('DNS order not found.');
        }
        return $model;
    }

    private function record(OODBBean $model, array $data): OODBBean
    {
        if (empty($data['record_id'])) throw new \FOSSBilling\InformationException('Record ID is required.');
        $record = $this->di['db']->findOne('service_dns_records', 'id = :id AND domain_id = :domain', [
            ':id' => (int)$data['record_id'], ':domain' => $model->id,
        ]);
        if (!$record instanceof OODBBean || !$record->id) {
            throw new \FOSSBilling\InformationException('Record not found. Please refresh and try again.');
        }
        return $record;
    }

    private function recordData(array $data, array $config): array
    {
        $type = strtoupper((string)($data['record_type'] ?? ''));
        if (!preg_match('/^[A-Z][A-Z0-9]{0,9}$/D', $type)) {
            throw new \FOSSBilling\InformationException('Unsupported record type.');
        }
        $value = $data['record_value'] ?? null;
        if (!is_string($value) || ($value === '' && $type !== 'TXT')) {
            throw new \FOSSBilling\InformationException('Record value is required.');
        }
        $ttl = filter_var($data['record_ttl'] ?? 3600, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        $priority = filter_var(($data['record_priority'] ?? '') === '' ? 0 : $data['record_priority'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
        if ($ttl === false || ($type === 'MX' && $priority === false)) {
            throw new \FOSSBilling\InformationException('Invalid TTL or MX priority.');
        }
        $name = strtolower(trim((string)($data['record_name'] ?? '')));
        $domain = $config['domain_name'];
        if ($name === '@' || rtrim($name, '.') === $domain) $name = '';
        if (str_ends_with($name, '.' . $domain . '.')) $name = substr($name, 0, -strlen($domain) - 2);
        if (str_ends_with($name, '.' . $domain)) $name = substr($name, 0, -strlen($domain) - 1);
        if ($name !== '' && (!preg_match('/^(?:\*|[a-z0-9_-]+)(?:\.[a-z0-9_-]+)*$/D', $name) || strlen($name . '.' . $domain) > 253)) {
            throw new \FOSSBilling\InformationException('Use a relative record name such as www, or @ for the zone apex.');
        }
        // Zone-file APIs need quotes; JSON content APIs expect the original TXT value.
        if (in_array($type, ['TXT', 'SPF'], true) && in_array($config['provider'], ['Desec', 'PowerDNS'], true)) {
            if (!(str_starts_with($value, '"') && str_ends_with($value, '"'))) {
                $value = '"' . addcslashes($value, "\\\"") . '"';
            }
        }
        if ($config['provider'] === 'PowerDNS' && $type === 'CNAME') $value = rtrim(trim($value), '.') . '.';
        return [
            'record_name' => $name, 'record_type' => $type, 'record_value' => $value,
            'record_ttl' => $ttl, 'record_priority' => $type === 'MX' ? $priority : null,
        ];
    }

    public function addRecord(array $data, $identity = null): bool
    {
        $model = $this->managedZone($data, $identity);
        $config = $this->providerConfig($model);
        $this->plex()->addRecord($this->recordData($data, $config) + $config);
        return true;
    }

    public function updateRecord(array $data, $identity = null): bool
    {
        $model = $this->managedZone($data, $identity);
        $record = $this->record($model, $data);
        $config = $this->providerConfig($model);
        // Record identity and old value come from the selected local row, not form values.
        $data['record_name'] = $record->host;
        $data['record_type'] = $record->type;
        $data['record_priority'] ??= $record->priority;
        $req = $this->recordData($data, $config);
        $req['record_id'] = (int)$record->id;
        $req['old_value'] = $record->value;
        $this->plex()->updateRecord($req + $config);
        return true;
    }

    public function delRecord(array $data, $identity = null): bool
    {
        $model = $this->managedZone($data, $identity);
        $record = $this->record($model, $data);
        $this->plex()->delRecord([
            'record_id' => (int)$record->id, 'record_name' => $record->host,
            'record_type' => $record->type, 'record_value' => $record->value,
            'record_priority' => $record->priority,
        ] + $this->providerConfig($model));
        return true;
    }

    public function getDnssec(array $data, $identity = null): array
    {
        $model = $this->managedZone($data, $identity);
        $config = $this->providerConfig($model);
        $plex = $this->plex();

        $capabilities = $plex->getDNSSECCapabilities($config);

        if (!$capabilities['supported']) {
            return array_merge($capabilities, [
                'enabled' => false,
                'ds' => [],
            ]);
        }

        $status = $plex->getDNSSECStatus($config);

        $ds = $status['ds'] ?? null;

        if ($ds === null) {
            $ds = $plex->getDSRecords($config);
        }

        if ($ds === null || $ds === '') {
            $ds = [];
        } elseif (!is_array($ds)) {
            $ds = [$ds];
        }

        return array_merge($capabilities, $status, [
            'enabled' => (bool)($status['enabled'] ?? $capabilities['enforced']),
            'ds' => $ds,
        ]);
    }

    public function enableDnssec(array $data, $identity = null): bool
    {
        $model = $this->managedZone($data, $identity);
        $config = $this->providerConfig($model);
        $plex = $this->plex();

        $capabilities = $plex->getDNSSECCapabilities($config);

        if (!$capabilities['supported'] || !$capabilities['can_enable']) {
            throw new \FOSSBilling\InformationException(
                'DNSSEC cannot be enabled for this DNS provider.'
            );
        }

        $plex->enableDNSSEC($config);

        return true;
    }

    public function disableDnssec(array $data, $identity = null): bool
    {
        $model = $this->managedZone($data, $identity);
        $config = $this->providerConfig($model);
        $plex = $this->plex();

        $capabilities = $plex->getDNSSECCapabilities($config);

        if (!$capabilities['supported'] || !$capabilities['can_disable']) {
            throw new \FOSSBilling\InformationException(
                'DNSSEC cannot be disabled for this DNS provider.'
            );
        }

        $plex->disableDNSSEC($config);

        return true;
    }

    /**
     * Creates the database structure to store the DNS records in.
     */
    public function install(): bool
    {
        $sql = '
        CREATE TABLE IF NOT EXISTS `service_dns` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `client_id` BIGINT(20) NOT NULL,
            `domain_name` VARCHAR(253),
            `provider_id` VARCHAR(11),
            `zoneId` VARCHAR(100) DEFAULT NULL,
            `config` TEXT NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_domain_name` (`domain_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE IF NOT EXISTS `service_dns_records` (
            `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
            `domain_id` BIGINT(20) NOT NULL,
            `recordId` VARCHAR(100) DEFAULT NULL,
            `type` VARCHAR(10) NOT NULL,
            `host` VARCHAR(255) NOT NULL,
            `value` TEXT NOT NULL,
            `ttl` INT(11) DEFAULT NULL,
            `priority` INT(11) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            FOREIGN KEY (`domain_id`) REFERENCES `service_dns`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
        $this->di['db']->exec($sql);
        $this->migrate();

        return true;
    }

    /**
     * Removes the DNS records from the database.
     */
    public function uninstall(): bool
    {
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_dns_records`');
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_dns`');

        return true;
    }


    public function update(array $manifest): bool
    {
        return $this->install();
    }

    private function migrate(): void
    {
        $pdo = $this->di['pdo'];
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec('ALTER TABLE service_dns MODIFY domain_name VARCHAR(253)');
        }
        $pdo->beginTransaction();
        try {
            // Keep credentials in the private service snapshot, never in client-visible orders.
            $orders = $pdo->query("SELECT id, service_id, client_id, config, activated_at FROM client_order WHERE service_type = 'dns'");
            foreach ($orders->fetchAll(\PDO::FETCH_ASSOC) as $order) {
                $config = $this->decodeConfig($order['config']);
                if ($order['service_id']) {
                    $statement = $pdo->prepare('SELECT config FROM service_dns WHERE id = ? AND client_id = ?');
                    $statement->execute([$order['service_id'], $order['client_id']]);
                    $snapshot = $statement->fetchColumn();
                    if ($snapshot !== false) {
                        $private = array_replace($config, $this->decodeConfig($snapshot));
                        if (!empty($order['activated_at'])) $private['_provisioned'] = true;
                        $pdo->prepare('UPDATE service_dns SET config = ? WHERE id = ?')->execute([json_encode($private, JSON_THROW_ON_ERROR), $order['service_id']]);
                    }
                }
                $pdo->prepare('UPDATE client_order SET config = ? WHERE id = ?')->execute([json_encode($this->publicConfig($config), JSON_THROW_ON_ERROR), $order['id']]);
            }
            $carts = $pdo->query("SELECT cp.id, cp.config FROM cart_product cp JOIN product p ON p.id = cp.product_id WHERE p.type = 'dns'");
            foreach ($carts->fetchAll(\PDO::FETCH_ASSOC) as $cart) {
                $pdo->prepare('UPDATE cart_product SET config = ? WHERE id = ?')->execute([json_encode($this->publicConfig($this->decodeConfig($cart['config'])), JSON_THROW_ON_ERROR), $cart['id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
