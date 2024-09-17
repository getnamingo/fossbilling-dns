<?php

namespace Box\Mod\Servicedns\Providers;

use Vultr\VultrPhp\Services\DNS\DNSService;
use Vultr\VultrPhp\Services\DNS\Domain;
use Vultr\VultrPhp\Services\DNS\Record;
use Vultr\VultrPhp\Services\DNS\DNSSOA;
use Vultr\VultrPhp\Services\DNS\DNSException;
use Vultr\VultrPhp\VultrClient;

class Vultr implements DnsHostingProviderInterface {
    private $client;
    
    public function __construct($config) {
        $token = $config['apikey'];
        if (empty($token)) {
            throw new \FOSSBilling\InformationException("API token cannot be empty");
        }

        $this->client = VultrClient::create($token);
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \FOSSBilling\InformationException("Domain name cannot be empty");
        }

        try {
            $response = $this->client->dns->createDomain($domainName);
            return json_decode($response->getDomain(), true);
        } catch (Exception $e) {
            throw new \FOSSBilling\InformationException("Error creating domain: " . $e->getMessage());
        }
    }

    public function listDomains() {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function getDomain($domainName) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function getResponsibleDomain($qname) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function exportDomainAsZonefile($domainName) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function deleteDomain($domainName) {
        if (empty($domainName)) {
            throw new \FOSSBilling\InformationException("Domain name cannot be empty");
        }

        try {
            $response = $this->client->dns->deleteDomain($domainName);
            return json_decode($response->getDomain(), true);
        } catch (Exception $e) {
            throw new \FOSSBilling\InformationException("Error deleting domain: " . $e->getMessage());
        }
    }
    
    public function createRRset($domainName, $rrsetData) {
        try {
            $record = new Record();

            if (isset($rrsetData['type'])) {
                $record->setType($rrsetData['type']);
            }
            if (isset($rrsetData['subname'])) {
                $record->setName($rrsetData['subname']);
            }
            if (isset($rrsetData['records'])) {
                $record->setData($rrsetData['records'][0]);
            }
            if (isset($rrsetData['priority'])) {
                $record->setPriority($rrsetData['priority']);
            } else {
                $record->setPriority(0);
            }
            if (isset($rrsetData['ttl'])) {
                $record->setTtl($rrsetData['ttl']);
            }

            $response = $this->client->dns->createRecord($domainName, $record);
            return json_decode($domainName, true);
        } catch (Exception $e) {
            throw new \FOSSBilling\InformationException("Error creating record: " . $e->getMessage());
        }
    }

    public function createBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function retrieveAllRRsets($domainName) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function retrieveSpecificRRset($domainName, $subname, $type) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData) {
        try {
            $records = $this->client->dns->getRecords($domainName);
            foreach ($records as $record) {
                if ($record instanceof \Vultr\VultrPhp\Services\DNS\Record) {
                    if ($type === 'MX') {
                        // For MX records, compare type, and data
                        if ($record->getType() === $type && $record->getData() === $rrsetData['records'][0]) {
                            $recordId = $record->getId();
                            break; // Stop the loop once the record is found
                        }
                    } else {
                        // For non-MX records, compare only name and type
                        if ($record->getName() === $subname && $record->getType() === $type) {
                            $recordId = $record->getId();
                            break; // Stop the loop once the record is found
                        }
                    }
                }
            }
            
            if ($recordId === null) {
                throw new \FOSSBilling\InformationException("Error: No record found with name '$subname' and type '$type'");
            }

            $record = new Record();
            
            if (isset($recordId)) {
                $record->setId($recordId);
            } else {
                throw new \FOSSBilling\InformationException("Record ID is required for updating");
            }
            if (isset($type)) {
                $record->setType($type);
            }
            if (isset($subname)) {
                $record->setName($subname);
            }
            if (isset($rrsetData['records'])) {
                $record->setData($rrsetData['records'][0]);
            }
            $record->setPriority(0);
            if (isset($rrsetData['ttl'])) {
                $record->setTtl($rrsetData['ttl']);
            }

            $response = $this->client->dns->updateRecord($domainName, $record);
            return json_decode($domainName, true);
        } catch (Exception $e) {
            throw new \FOSSBilling\InformationException("Error updating record: " . $e->getMessage());
        }
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        try {
            $records = $this->client->dns->getRecords($domainName);
            foreach ($records as $record) {
                if ($record instanceof \Vultr\VultrPhp\Services\DNS\Record) {
                    if ($type === 'MX') {
                        // For MX records, compare type, and data
                        if ($record->getType() === $type && $record->getData() === $value) {
                            $recordId = $record->getId();
                            break; // Stop the loop once the record is found
                        }
                    } else {
                        // For non-MX records, compare only name and type
                        if ($record->getName() === $subname && $record->getType() === $type) {
                            $recordId = $record->getId();
                            break; // Stop the loop once the record is found
                        }
                    }
                }
            }
            
            if ($recordId === null) {
                throw new \FOSSBilling\InformationException("Error: No record found with name '$subname' and type '$type'");
            }

            $response = $this->client->dns->deleteRecord($domainName, $recordId);
            return json_decode($domainName, true);
        } catch (Exception $e) {
            throw new \FOSSBilling\InformationException("Error deleting record: " . $e->getMessage());
        }
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\InformationException("Not yet implemented");
    }
    
}