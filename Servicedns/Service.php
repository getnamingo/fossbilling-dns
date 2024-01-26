<?php
/**
 * Copyright 2022-2023 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicedns;

use FOSSBilling\InjectionAwareInterface;
use RedBeanPHP\OODBBean;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    private $dnsProvider;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }
    
    // Method to dynamically choose and set the DNS provider
    private function chooseDnsProvider() {
        // Example logic to choose the provider
        // This could be based on configuration, user input, etc.
        $providerName = 'Desec'; // This is just an example
        $apiToken = ''; // This is just an example

        switch ($providerName) {
            case 'Desec':
                $this->dnsProvider = new Providers\Desec($apiToken);
                break;
            // Add more cases for additional providers
            default:
                throw new \FOSSBilling\Exception("Unknown DNS provider: {$providerName}");
        }
    }

    public function attachOrderConfig(\Model_Product $product, array $data): array
    {
        !empty($product->config) ? $config = json_decode($product->config, true) : $config = [];

        return array_merge($config, $data);
    }

    public function create(OODBBean $order)
    {
        $model = $this->di['db']->dispense('service_dns');
        $model->client_id = $order->client_id;
        $model->config = $order->config;
        $domainName = isset($order->config) ? json_decode($order->config)->domain_name : null;
        $model->domain_name = $domainName;

        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return $model;
    }

    public function activate(OODBBean $order, OODBBean $model): bool
    {
        $config = json_decode($order->config, 1);
        if (!is_object($model)) {
            throw new \FOSSBilling\Exception('Order does not exist.');
        }

        $domainName = isset($order->config) ? json_decode($order->config)->domain_name : null;
        $model->domain_name = $domainName;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        
        $this->chooseDnsProvider();
        if ($this->dnsProvider === null) {
            throw new \FOSSBilling\Exception("DNS provider is not set.");
        }

        $this->dnsProvider->createDomain($domainName);

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
        $this->chooseDnsProvider();
        if ($this->dnsProvider === null) {
            throw new \FOSSBilling\Exception("DNS provider is not set.");
        }

        $this->dnsProvider->deleteDomain($domainName);

        if (is_object($model)) {
            $this->di['db']->trash($model);
        }
    }

    public function toApiArray(OODBBean $model): array
    {
        return [
            'id' => $model->id,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
            'domain_name' => $model->domain_name,
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
        $this->chooseDnsProvider();

        // Check if DNS provider is set
        if ($this->dnsProvider === null) {
            throw new \FOSSBilling\Exception("DNS provider is not set.");
        }
              
        if (!empty($data['order_id'])) {
            $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
            $orderService = $this->di['mod_service']('order');
            $model = $orderService->getOrderService($order);
        }
        if (is_null($model)) {
            throw new \FOSSBilling\Exception('Domain does not exist');
        }

        try {
            $this->di['is_client_logged'];
            $client = $this->di['loggedin_client'];
        } catch (\Exception) {
            $client = null;
        }

        if (!is_null($client) && $client->id !== $model->client_id) {
            throw new \FOSSBilling\Exception('Domain does not exist');
        }

        $config = json_decode($model->config, true);
        $rrsetData = [
            'subname' => $data['record_name'],
            'type' => $data['record_type'],
            'ttl' => (int) $data['record_ttl'],
            'records' => [$data['record_value']]
        ];

        $this->dnsProvider->createRRset($config['domain_name'], $rrsetData);
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
        
        $records = $this->di['db']->dispense('service_dns_records');
        $domainName = isset($order->config) ? json_decode($order->config)->domain_name : null;
        $domain_id = $this->di['db']->findOne('service_dns', 'domain_name = :domain_name', [':domain_name' => $domainName]);
        $records->domain_id = $domain_id['id'];
        $records->type = $data['record_type'];
        $records->host = $data['record_name'];
        $records->value = $data['record_value'];
        $records->ttl = (int) $data['record_ttl'];
        $records->priority = 0;
        $records->created_at = date('Y-m-d H:i:s');
        $records->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($records);

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
        throw new \FOSSBilling\Exception('Not yet implemented');
        return true;
    }

    /**
     * Used to update a DNS record for a specified domain.
     *
     * @param array $data An array containing the identification information of the DNS record to be updated.
     *
     * @return bool Returns true if the DNS record was successfully updated, false otherwise.
     */
    public function updateRecord(array $data): bool
    {
        throw new \FOSSBilling\Exception('Not yet implemented');
        return true;
        
        if (empty($data['order_id'])) {
            throw new \FOSSBilling\Exception('You must provide the API key order ID in order to update it.');
        }

        $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        $orderService = $this->di['mod_service']('order');
        $model = $orderService->getOrderService($order);

        if (is_null($model)) {
            throw new \FOSSBilling\Exception('API key does not exist');
        }

        if (isset($data['domain_name']) && $model->domain_name !== $data['domain_name']) {
            throw new \FOSSBilling\Exception('To change the API key, please use the reset function rather than updating it.');
        }

        $config = !empty($data['config']) ? json_encode($data['config']) : $model->config;

        // ID and client ID should remain constant so we don't try to update those here.
        $model->config = $config;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    /**
     * Creates the database structure to store the DNS records in.
     */
    public function install(): bool
    {
        $sql = '
        CREATE TABLE IF NOT EXISTS `service_dns` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT UNIQUE,
            `client_id` bigint(20) NOT NULL,
            `domain_name` varchar(75),
            `provider_id` varchar(11),
            `config` text NOT NULL,
            `created_at` datetime,
            `updated_at` datetime,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
        CREATE TABLE IF NOT EXISTS `service_dns_records` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `domain_id` bigint(20) NOT NULL,
            `type` varchar(10) NOT NULL,
            `host` varchar(255) NOT NULL,
            `value` text NOT NULL,
            `ttl` int(11) DEFAULT NULL,
            `priority` int(11) DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            FOREIGN KEY (`domain_id`) REFERENCES `service_dns`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
        CREATE TABLE IF NOT EXISTS `service_dns_providers` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `host` varchar(255) NOT NULL,
            `api_key` varchar(255) NOT NULL,
            `api_password` varchar(255) NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;';
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
        $this->di['db']->exec('DROP TABLE IF EXISTS `service_dns_providers`');

        return true;
    }

    private function isActive(OODBBean $model): bool
    {
        $order = $this->di['db']->findOne('ClientOrder', 'service_id = :id AND service_type = "dns"', [':id' => $model->id]);
        if (is_null($order)) {
            throw new \FOSSBilling\Exception('DNS record does not exist');
        }

        return $order->status === 'active';
    }
}