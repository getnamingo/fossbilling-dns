<?php

namespace Box\Mod\Servicedns\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;

class Hetzner implements DnsHostingProviderInterface {
    private $baseUrl = "https://dns.hetzner.com/api/v1/";
    private $client;
    private $headers;
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

        $this->client = new Client(['base_uri' => $this->baseUrl]);
        $this->headers = [
            'Auth-API-Token' => $token,
            'Content-Type' => 'application/json',
        ];
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \FOSSBilling\Exception("Domain name cannot be empty");
        }
            
        try {
            $response = $this->client->request('POST', 'zones', [
                'headers' => $this->headers,
                'json' => ['name' => $domainName]
            ]);
            
            $body = json_decode($response->getBody()->getContents(), true);
            $zoneId = $body['zone']['id'] ?? null;
            
            try {
                $sql = "UPDATE service_dns SET zoneId = :zoneId WHERE domain_name = :domainName";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':zoneId', $zoneId, PDO::PARAM_STR);
                $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
                $stmt->execute();

                if ($stmt->rowCount() === 0) {
                    throw new \FOSSBilling\Exception("No DB update made. Check if the domain name exists.");
                }
            } catch (\PDOException $e) {
                throw new \FOSSBilling\Exception("Error updating zoneId: " . $e->getMessage());
            }
            
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            throw new \FOSSBilling\Exception('Request failed: ' . $e->getMessage());
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
            $sql = "SELECT zoneId FROM service_dns WHERE domain_name = :domainName LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $zoneId = $row['zoneId'];
            } else {
                throw new \FOSSBilling\Exception("Domain name does not exist.");
            }
        } catch (\PDOException $e) {
            throw new \FOSSBilling\Exception("Error fetching zoneId: " . $e->getMessage());
        }

        try {
            $response = $this->client->request('DELETE', "zones/{$zoneId}", [
                'headers' => $this->headers,
            ]);

            if ($response->getStatusCode() === 204) {
                return true;
            } else {
                return false;
            }
        } catch (GuzzleException $e) {
            throw new \FOSSBilling\Exception('Request failed: ' . $e->getMessage());
        }
    }
    
    public function createRRset($domainName, $rrsetData) {
        if (empty($domainName)) {
            throw new \FOSSBilling\Exception("Domain name cannot be empty");
        }
        
        try {
            $sql = "SELECT zoneId FROM service_dns WHERE domain_name = :domainName LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $zoneId = $row['zoneId'];
            } else {
                throw new \FOSSBilling\Exception("Domain name does not exist.");
            }
        } catch (\PDOException $e) {
            throw new \FOSSBilling\Exception("Error fetching zoneId: " . $e->getMessage());
        }

        try {
            $response = $this->client->request('POST', 'records', [
                'headers' => $this->headers,
                'json' => [
                    'value' => $rrsetData['records'][0],
                    'ttl' => $rrsetData['ttl'],
                    'type' => $rrsetData['type'],
                    'name' => $rrsetData['subname'],
                    'zone_id' => $zoneId
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $body = json_decode($response->getBody()->getContents(), true);
                $recordId = $body['record']['id'] ?? null;
                
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

                return true;
            } else {
                return false;
            }
        } catch (GuzzleException $e) {
            throw new \FOSSBilling\Exception('Request failed: ' . $e->getMessage());
        } catch (\PDOException $e) {
            throw new \FOSSBilling\Exception("Error updating zoneId: " . $e->getMessage());
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
        if (empty($domainName)) {
            throw new \FOSSBilling\Exception("Domain name cannot be empty");
        }
            
        try {
            $sql = "SELECT id, zoneId FROM service_dns WHERE domain_name = :domainName LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $zoneId = $row['zoneId'];
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
                throw new \FOSSBilling\Exception("Record not found for the given type and subname.");
            }

            $response = $this->client->request('PUT', "records/{$recordId}", [
                'headers' => $this->headers,
                'json' => [
                    'value' => $rrsetData['records'][0],
                    'ttl' => $rrsetData['ttl'],
                    'type' => $type,
                    'name' => $subname,
                    'zone_id' => $zoneId
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                return true;
            } else {
                return false;
            }
        } catch (GuzzleException $e) {
            throw new \FOSSBilling\Exception('Request failed: ' . $e->getMessage());
        } catch (\PDOException $e) {
            throw new \FOSSBilling\Exception("Error in operation: " . $e->getMessage());
        }
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        try {
            $sql = "SELECT id, zoneId FROM service_dns WHERE domain_name = :domainName LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':domainName', $domainName, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $zoneId = $row['zoneId'];
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
                throw new \FOSSBilling\Exception("Record not found for the given type and subname.");
            }

            $response = $this->client->request('DELETE', "records/{$recordId}", [
                'headers' => $this->headers,
            ]);
            
            if ($response->getStatusCode() === 204) {
                return true;
            } else {
                return false;
            }
        } catch (GuzzleException $e) {
            throw new \FOSSBilling\Exception('Request failed: ' . $e->getMessage());
        } catch (\PDOException $e) {
            throw new \FOSSBilling\Exception("Error in operation: " . $e->getMessage());
        }
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }
    
}