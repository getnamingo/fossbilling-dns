<?php

namespace Box\Mod\Servicedns\Providers;

use Dnsimple\Client;
use PDO;

class DNSimple implements DnsHostingProviderInterface {
    private $client;
    private $account_id;
    private $dbConfig;
    private $pdo;
    
    public function __construct($config) {
        // Load DB configuration
        $dbc = include __DIR__ . '/../../../config.php';
        $this->dbConfig = $dbc['db'];
        
        try {
            $dsn = $this->dbConfig["type"] . ":host=" . $this->dbConfig["host"] . ";port=" . $this->dbConfig["port"] . ";dbname=" . $this->dbConfig["name"];
            $this->pdo = new PDO($dsn, $this->dbConfig['user'], $this->dbConfig['password']);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            throw new \FOSSBilling\Exception("Connection failed: " . $e->getMessage());
        }

        $token = $config['apikey'];
        if (empty($token)) {
            throw new \FOSSBilling\Exception("API token cannot be empty");
        }

        $this->client = new Client($token);
        $this->account_id = $this->client->identity->whoami()->getData()->account->id;
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \FOSSBilling\Exception("Domain name cannot be empty");
        }

        try {
            $response = $this->client->domains->createDomain($this->account_id, ["name" => $domainName]);
            return json_decode($response->getData()->name, true);
        } catch (Exception $e) {
            throw new \FOSSBilling\Exception("Error creating domain: " . $e->getMessage());
        }
    }

    public function listDomains() {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function getDomain($domainName) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function getResponsibleDomain($qname) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function exportDomainAsZonefile($domainName) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function deleteDomain($domainName) {
        if (empty($domainName)) {
            throw new \FOSSBilling\Exception("Domain name cannot be empty");
        }

        try {
            $this->client->domains->deleteDomain($this->account_id, $domainName);
            return true;
        } catch (Exception $e) {
            throw new \FOSSBilling\Exception("Error deleting domain: " . $e->getMessage());
        }
    }
    
    public function createRRset($domainName, $rrsetData) {
        try {
            $record = [];

            if (isset($rrsetData['type'])) {
                $record['type'] = $rrsetData['type'];
            }
            if (isset($rrsetData['subname'])) {
                $record['name'] = $rrsetData['subname'];
            }
            if (isset($rrsetData['records'])) {
                $record['content'] = $rrsetData['records'][0];
            }
            if (isset($rrsetData['priority'])) {
                $record['priority'] = $rrsetData['priority'];
            } else {
                $record['priority'] = 0;
            }
            if (isset($rrsetData['ttl'])) {
                $record['ttl'] = $rrsetData['ttl'];
            }
            
            $response = $this->client->zones->createRecord($this->account_id, $domainName, $record);
            $recordId = $response->getData()->id;
            
            try {              
                $sql = "SELECT id FROM service_dns WHERE domain_name = :domainName LIMIT 1";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $domainId = $row['id'];
                } else {
                    throw new \FOSSBilling\Exception("Domain name does not exist.");
                }
            
                $sql = "UPDATE service_dns_records SET recordId = :recordId WHERE type = :type AND host = :subname AND value = :value AND domain_id = :domain_id";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':recordId', $recordId, PDO::PARAM_STR);
                $stmt->bindParam(':type', $rrsetData['type'], PDO::PARAM_STR);
                $stmt->bindParam(':subname', $rrsetData['subname'], PDO::PARAM_STR);
                $stmt->bindParam(':value', $rrsetData['records'][0], PDO::PARAM_STR);
                $stmt->bindParam(':domain_id', $domainId, PDO::PARAM_INT);
                $stmt->execute();

                if ($stmt->rowCount() === 0) {
                    throw new \FOSSBilling\Exception("No DB update made. Check if the domain name exists.");
                }
            } catch (\PDOException $e) {
                throw new \FOSSBilling\Exception("Error updating zoneId: " . $e->getMessage());
            }
                
            return true;
        } catch (Exception $e) {
            throw new \FOSSBilling\Exception("Error creating record: " . $e->getMessage());
        }
    }

    public function createBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function retrieveAllRRsets($domainName) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function retrieveSpecificRRset($domainName, $subname, $type) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData) {
        try {
            $sql = "SELECT id FROM service_dns WHERE domain_name = :domainName LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $domainId = $row['id'];
            } else {
                throw new \FOSSBilling\Exception("Domain name does not exist.");
            }

            $sql = "SELECT recordId FROM service_dns_records WHERE type = :type AND host = :subname AND domain_id = :domain_id LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':type', $type, PDO::PARAM_STR);
            $stmt->bindParam(':subname', $subname, PDO::PARAM_STR);
            $stmt->bindParam(':domain_id', $domainId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $recordId = $row['recordId'];
            } else {
                throw new \FOSSBilling\Exception("Error: No record found with name '$subname' and type '$type'");
            }
            
            $record = [];

            if (isset($type)) {
                $record['type'] = $type;
            }
            if (isset($subname)) {
                $record['name'] = $subname;
            }
            if (isset($rrsetData['records'])) {
                $record['content'] = $rrsetData['records'][0];
            }
            if (isset($rrsetData['priority'])) {
                $record['priority'] = $rrsetData['priority'];
            } else {
                $record['priority'] = 0;
            }
            if (isset($rrsetData['ttl'])) {
                $record['ttl'] = $rrsetData['ttl'];
            }

            $response = $this->client->zones->updateRecord($this->account_id, $domainName, $recordId, $record);
            
            if ($response->getStatusCode() === 200) {
                return true;
            } else {
                return false;
            }
        } catch (Exception $e) {
            throw new \FOSSBilling\Exception("Error updating record: " . $e->getMessage());
        } catch (\PDOException $e) {
            throw new \FOSSBilling\Exception("Error in operation: " . $e->getMessage());
        }
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        try {
            $sql = "SELECT id FROM service_dns WHERE domain_name = :domainName LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $domainId = $row['id'];
            } else {
                throw new \FOSSBilling\Exception("Domain name does not exist.");
            }

            $sql = "SELECT recordId FROM service_dns_records WHERE type = :type AND host = :subname AND domain_id = :domain_id LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':type', $type, PDO::PARAM_STR);
            $stmt->bindParam(':subname', $subname, PDO::PARAM_STR);
            $stmt->bindParam(':domain_id', $domainId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $recordId = $row['recordId'];
            } else {
                throw new \FOSSBilling\Exception("Error: No record found with name '$subname' and type '$type'");
            }
            
            $response = $this->client->zones->deleteRecord($this->account_id, $domainName, $recordId);
            
            if ($response->getStatusCode() === 200) {
                return true;
            } else {
                return false;
            }
        } catch (Exception $e) {
            throw new \FOSSBilling\Exception("Error deleting record: " . $e->getMessage());
        }
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }
    
}