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

define('PLEX_TABLE_ZONES', 'service_dns');
define('PLEX_TABLE_RECORDS', 'service_dns_records');

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use FOSSBilling\InjectionAwareInterface;
use RedBeanPHP\OODBBean;
use PlexDNS\Service as PlexService;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getCartProductTitle($product, array $data)
    {
        return __trans('DNS Hosting for :domain', [':domain' => $data['domain_name']]);
    }

    public function attachOrderConfig(\Model_Product $product, array $data): array
    {
        !empty($product->config) ? $config = json_decode($product->config, true) : $config = [];

        return array_merge($config, $data);
    }

    public function create(OODBBean $order)
    {
        $config = json_decode((string)$order->config, true) ?: [];
        $domainName = $config['domain_name'] ?? null;

        $model = $this->di['db']->dispense('service_dns');
        $model->client_id   = $order->client_id;
        $model->config      = $order->config;
        $model->domain_name = $domainName;

        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');

        $this->di['db']->store($model);

        $order->service_id = $model->id;
        $order->title = $domainName ? ('DNS: ' . $domainName) : 'DNS';
        $this->di['db']->store($order);

        return $model;
    }

    public function activate(OODBBean $order, OODBBean $model): bool
    {
        if (!$model || empty($model->id)) {
            throw new \FOSSBilling\InformationException('Order does not exist.');
        }

        $config = json_decode((string)$order->config, true) ?: [];
        $domainName = $config['domain_name'] ?? null;
        $provider   = $config['provider'] ?? null;
        $apikey     = $config['apikey'] ?? null;

        $model->domain_name = $domainName;
        $model->updated_at  = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        $service = new PlexService($this->di['pdo']);

        $cfg = [
            'domain_name' => $domainName,
            'provider'    => $provider,
            'apikey'      => $apikey,
        ];

        if ($provider === 'PowerDNS') {
            $cfg['powerdnsip'] = $config['powerdnsip'] ?? null;
            for ($i = 1; $i <= 13; $i++) {
                $k = 'ns' . $i;
                if (!empty($config[$k])) $cfg[$k] = $config[$k];
            }
        } elseif ($provider === 'Bind') {
            $cfg['bindip'] = $config['bindip'] ?? null;
            for ($i = 1; $i <= 13; $i++) {
                $k = 'ns' . $i;
                if (!empty($config[$k])) $cfg[$k] = $config[$k];
            }
        }

        $domainOrder = [
            'client_id' => $order->client_id,
            'config'    => json_encode($cfg, JSON_UNESCAPED_SLASHES),
        ];

        $domain = $service->createDomain($domainOrder);

        $model->config      = $order->config;
        $this->di['db']->store($model);

        return true;
    }

    public function suspend(OODBBean $order, OODBBean $model): bool
    {
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function unsuspend(OODBBean $order, OODBBean $model): bool
    {
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    public function cancel(OODBBean $order, OODBBean $model): bool
    {
        return $this->suspend($order, $model);
    }

    public function uncancel(OODBBean $order, OODBBean $model): bool
    {
        return $this->unsuspend($order, $model);
    }

    public function delete(?OODBBean $order, ?OODBBean $model): void
    {
        if ($order === null) {
            throw new \FOSSBilling\InformationException("Order is not provided.");
        }

        $config = json_decode((string)$order->config, true) ?: [];
        $domainName = $config['domain_name'] ?? null;
        $provider   = $config['provider'] ?? null;
        $apiKey     = $config['apikey'] ?? null;

        if (empty($domainName)) {
            throw new \FOSSBilling\InformationException('Domain name is not set.');
        }
        if (empty($provider)) {
            throw new \FOSSBilling\InformationException('DNS provider is not set.');
        }

        try {
            $service = new PlexService($this->di['pdo']);

            $cfg = [
                'domain_name' => $domainName,
                'provider'    => $provider,
                'apikey'      => $apiKey,
            ];

            if ($provider === 'PowerDNS') {
                $cfg['powerdnsip'] = $config['powerdnsip'] ?? null;
            }

            if ($provider === 'Bind') {
                $cfg['bindip'] = $config['bindip'] ?? null;
            }

            if ($provider === 'PowerDNS' || $provider === 'Bind') {
                for ($i = 1; $i <= 13; $i++) {
                    $k = 'ns' . $i;
                    if (!empty($config[$k])) {
                        $cfg[$k] = $config[$k];
                    }
                }
            }

            $service->deleteDomain([
                'config' => json_encode($cfg, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            $msg = (string)$e->getMessage();

            if (
                stripos($msg, 'not found') !== false ||
                stripos($msg, '404') !== false
            ) {
                if (isset($this->di['logger'])) {
                    $this->di['logger']->warning(sprintf(
                        'DNS delete: domain "%s" not found, continuing. (%s)',
                        $domainName,
                        $msg
                    ));
                } else {
                    error_log(sprintf('DNS delete: domain "%s" not found, continuing. (%s)', $domainName, $msg));
                }
            } else {
                throw new \FOSSBilling\InformationException(
                    sprintf(
                        'Failed to delete DNS zone "%s": %s',
                        $domainName,
                        $msg
                    )
                );
            }
        }

        if (is_object($model)) {
            $this->di['db']->trash($model);
        }
    }

    public function toApiArray(OODBBean $model): array
    {
        $domain_id = $this->di['db']->findOne('service_dns', 'domain_name = :domain_name', [':domain_name' => $model->domain_name]);
        $records = $this->di['db']->getAll('SELECT id, type, host, value, ttl, priority FROM service_dns_records WHERE domain_id=:domain_id', ['domain_id' => $domain_id['id']]);

        return [
            'id' => $model->id,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
            'domain_name' => $model->domain_name,
            'records' => $records,
            'config' => json_decode($model->config, true),
        ];
    }

    /**
     * Used to add a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for adding a DNS record.
     * 
     * @return bool Returns true on successful addition of the DNS record, false otherwise.
     */
    public function addRecord(array $data): bool
    {           
        if (!empty($data['order_id'])) {
            $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
            $orderService = $this->di['mod_service']('order');
            $model = $orderService->getOrderService($order);
        }

        if (!$model instanceof OODBBean || empty($model->id)) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        try {
            $this->di['is_client_logged'];
            $client = $this->di['loggedin_client'];
        } catch (\Exception) {
            $client = null;
        }

        if ($client !== null && (int)$client->id !== (int)$model->client_id) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        $config = json_decode((string)$model->config, true) ?: [];
        $domainName = $config['domain_name'] ?? null;
        $provider   = $config['provider'] ?? null;
        $apiKey     = $config['apikey'] ?? null;
        
        if (empty($domainName)) {
            throw new \FOSSBilling\InformationException('Domain name is not set.');
        }
        if (empty($provider)) {
            throw new \FOSSBilling\InformationException('DNS provider is not set.');
        }
        
        $recordName  = (string)($data['record_name'] ?? '');
        $recordType  = strtoupper((string)($data['record_type'] ?? ''));
        $recordValue = (string)($data['record_value'] ?? '');
        $ttl         = isset($data['record_ttl']) ? (int)$data['record_ttl'] : 3600;
        $priority    = (isset($data['record_priority']) && $data['record_priority'] !== '') ? (int)$data['record_priority'] : null;

        if ($recordType === '' || $recordValue === '') {
            throw new \FOSSBilling\InformationException('Record type and value are required.');
        }

        if ($recordType === 'MX' && $priority === null) {
            $priority = 0;
        }

        if ($recordType === 'TXT') {
            $v = trim($recordValue);
            if ($v === '' || $v[0] !== '"' || substr($v, -1) !== '"') {
                $recordValue = '"' . str_replace('"', '\"', $v) . '"';
            }
        }

        if (in_array($provider, ['PowerDNS'], true) && $recordType === 'CNAME') {
            $recordValue = rtrim(trim($recordValue), '.') . '.';
        }

        try {
            $service = new PlexService($this->di['pdo']);

            $req = [
                'domain_name'     => $domainName,
                'record_name'     => $recordName,
                'record_type'     => $recordType,
                'record_value'    => $recordValue,
                'record_ttl'      => $ttl,
                'record_priority' => $priority,
                'provider'        => $provider,
                'apikey'          => $apiKey,
            ];

            if ($provider === 'PowerDNS') {
                $req['powerdnsip'] = $config['powerdnsip'] ?? null;
            }

            if ($provider === 'Bind') {
                $req['bindip'] = $config['bindip'] ?? null;
            }

            if ($provider === 'PowerDNS' || $provider === 'Bind') {
                for ($i = 1; $i <= 13; $i++) {
                    $k = 'ns' . $i;
                    if (!empty($config[$k])) {
                        $req[$k] = $config[$k];
                    }
                }
            }

            $service->addRecord($req);
        } catch (\Throwable $e) {
            throw new \FOSSBilling\InformationException(
                sprintf(
                    'Failed to add DNS record for "%s": %s',
                    $domainName,
                    $e->getMessage()
                )
            );
        }

        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }
    
    /**
     * Used to update a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for updating a DNS record.
     * 
     * @return bool Returns true on successful update of the DNS record, false otherwise.
     */
    public function updateRecord(array $data): bool
    {      
        if (!empty($data['order_id'])) {
            $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
            $orderService = $this->di['mod_service']('order');
            $model = $orderService->getOrderService($order);
        }

        if (!$model instanceof OODBBean || empty($model->id)) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        try {
            $this->di['is_client_logged'];
            $client = $this->di['loggedin_client'];
        } catch (\Exception) {
            $client = null;
        }

        if ($client !== null && (int)$client->id !== (int)$model->client_id) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }
        
        $config = json_decode((string)$model->config, true) ?: [];
        $domainName = $config['domain_name'] ?? null;
        $provider   = $config['provider'] ?? null;
        $apiKey     = $config['apikey'] ?? null;
        
        if (empty($domainName)) {
            throw new \FOSSBilling\InformationException('Domain name is not set.');
        }
        if (empty($provider)) {
            throw new \FOSSBilling\InformationException('DNS provider is not set.');
        }
        
        $recordName  = (string)($data['record_name'] ?? '');
        $recordType  = strtoupper((string)($data['record_type'] ?? ''));
        $recordValue = (string)($data['record_value'] ?? '');
        $oldValue    = (string)($data['old_value'] ?? '');
        $ttl         = isset($data['record_ttl']) ? (int)$data['record_ttl'] : 3600;
        $priority    = (isset($data['record_priority']) && $data['record_priority'] !== '') ? (int)$data['record_priority'] : null;

        if ($recordType === '' || $recordValue === '') {
            throw new \FOSSBilling\InformationException('Record type and value are required.');
        }

        if ($recordType === 'MX' && $priority === null) {
            $priority = 0;
        }

        if ($recordType === 'TXT') {
            $v = trim($recordValue);
            if ($v === '' || $v[0] !== '"' || substr($v, -1) !== '"') {
                $recordValue = '"' . str_replace('"', '\"', $v) . '"';
            }
        }

        if (in_array($provider, ['PowerDNS'], true) && $recordType === 'CNAME') {
            $recordValue = rtrim(trim($recordValue), '.') . '.';
        }

        try {
            $service = new PlexService($this->di['pdo']);

            $rec = $this->di['db']->findOne(
                'service_dns_records',
                'domain_id = :did AND type = :t AND host = :h AND value = :v',
                [
                    ':did' => (int)$model->id,
                    ':t'   => $recordType,
                    ':h'   => $recordName,
                    ':v'   => $oldValue,
                ]
            );

            if (!$rec instanceof OODBBean || empty($rec->id)) {
                throw new \FOSSBilling\InformationException('Record not found. Please refresh and try again.');
            }

            $recordId = $rec->recordId
                ?? $rec->recordid
                ?? ($rec->export()['recordId'] ?? null)
                ?? ($rec->export()['recordid'] ?? null);
            if (empty($recordId)) {
                throw new \FOSSBilling\InformationException('This record is missing provider recordId. Please delete and re-create it.');
            }

            $req = [
                'domain_name'     => $domainName,
                'record_id'       => $recordId,
                'record_name'     => $recordName,
                'record_type'     => $recordType,
                'record_value'    => $recordValue,
                'old_value'       => $oldValue,
                'record_ttl'      => $ttl,
                'record_priority' => $priority,
                'provider'        => $provider,
                'apikey'          => $apiKey,
            ];

            if ($provider === 'PowerDNS') {
                $req['powerdnsip'] = $config['powerdnsip'] ?? null;
            }

            if ($provider === 'Bind') {
                $req['bindip'] = $config['bindip'] ?? null;
            }

            if ($provider === 'PowerDNS' || $provider === 'Bind') {
                for ($i = 1; $i <= 13; $i++) {
                    $k = 'ns' . $i;
                    if (!empty($config[$k])) {
                        $req[$k] = $config[$k];
                    }
                }
            }

            $recordId = $service->updateRecord($req);
        } catch (\Throwable $e) {
            throw new \FOSSBilling\InformationException(
                sprintf(
                    'Failed to modify DNS record for "%s": %s',
                    $domainName,
                    $e->getMessage()
                )
            );
        }

        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }
    
    /**
     * Used to delete a DNS record for a specified domain.
     *
     * @param array $data An array containing the identification information of the DNS record to be deleted.
     *
     * @return bool Returns true if the DNS record was successfully deleted, false otherwise.
     */
    public function delRecord(array $data): bool
    {
        if (!empty($data['order_id'])) {
            $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
            $orderService = $this->di['mod_service']('order');
            $model = $orderService->getOrderService($order);
        }

        if (!$model instanceof OODBBean || empty($model->id)) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }

        try {
            $this->di['is_client_logged'];
            $client = $this->di['loggedin_client'];
        } catch (\Exception) {
            $client = null;
        }

        if ($client !== null && (int)$client->id !== (int)$model->client_id) {
            throw new \FOSSBilling\InformationException('Domain does not exist');
        }
        
        $config = json_decode((string)$model->config, true) ?: [];
        $domainName = $config['domain_name'] ?? null;
        $provider   = $config['provider'] ?? null;
        $apiKey     = $config['apikey'] ?? null;
        
        if (empty($domainName)) {
            throw new \FOSSBilling\InformationException('Domain name is not set.');
        }
        if (empty($provider)) {
            throw new \FOSSBilling\InformationException('DNS provider is not set.');
        }
        
        $recordName  = (string)($data['record_name'] ?? '');
        $recordType  = strtoupper((string)($data['record_type'] ?? ''));
        $recordValue = (string)($data['record_value'] ?? '');
        $ttl         = isset($data['record_ttl']) ? (int)$data['record_ttl'] : 3600;
        $priority    = (isset($data['record_priority']) && $data['record_priority'] !== '') ? (int)$data['record_priority'] : null;

        if ($recordType === '' || $recordValue === '') {
            throw new \FOSSBilling\InformationException('Record type and value are required.');
        }

        try {
            $service = new PlexService($this->di['pdo']);

            $rec = $this->di['db']->findOne(
                'service_dns_records',
                'domain_id = :did AND type = :t AND host = :h AND value = :v',
                [
                    ':did' => (int)$model->id,
                    ':t'   => $recordType,
                    ':h'   => $recordName,
                    ':v'   => $recordValue,
                ]
            );

            if (!$rec instanceof OODBBean || empty($rec->id)) {
                throw new \FOSSBilling\InformationException('Record not found. Please refresh and try again.');
            }

            $recordId = $rec->recordId
                ?? $rec->recordid
                ?? ($rec->export()['recordId'] ?? null)
                ?? ($rec->export()['recordid'] ?? null);
            if (empty($recordId)) {
                throw new \FOSSBilling\InformationException('This record is missing provider recordId. Please delete and re-create it.');
            }

            $req = [
                'domain_name'   => $domainName,
                'record_id'     => $recordId,
                'record_name'   => $recordName,
                'record_type'   => $recordType,
                'record_value'  => $recordValue,
                'provider'      => $provider,
                'apikey'        => $apiKey,
            ];

            if ($provider === 'PowerDNS') {
                $req['powerdnsip'] = $config['powerdnsip'] ?? null;
            }

            if ($provider === 'Bind') {
                $req['bindip'] = $config['bindip'] ?? null;
            }

            if ($provider === 'PowerDNS' || $provider === 'Bind') {
                for ($i = 1; $i <= 13; $i++) {
                    $k = 'ns' . $i;
                    if (!empty($config[$k])) {
                        $req[$k] = $config[$k];
                    }
                }
            }

            $recordId = $service->delRecord($req);
        } catch (\Throwable $e) {
            throw new \FOSSBilling\InformationException(
                sprintf(
                    'Failed to delete DNS record for "%s": %s',
                    $domainName,
                    $e->getMessage()
                )
            );
        }

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
            `domain_name` VARCHAR(75),
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

    private function isActive(OODBBean $model): bool
    {
        $order = $this->di['db']->findOne('ClientOrder', 'service_id = :id AND service_type = "dns"', [':id' => $model->id]);
        if (is_null($order)) {
            throw new \FOSSBilling\InformationException('DNS record does not exist');
        }

        return $order->status === 'active';
    }

}