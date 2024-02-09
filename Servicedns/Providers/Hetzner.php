<?php

namespace Box\Mod\Servicedns\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use SleekDB\Store;

class Hetzner implements DnsHostingProviderInterface {
    private $baseUrl = "https://dns.hetzner.com/api/v1/";
    private $client;
    private $headers;
    private $store;

    public function __construct($config) {
        $token = $config['apikey'];
        if (empty($token)) {
            throw new \FOSSBilling\Exception("API token cannot be empty");
        }

        $this->client = new Client(['base_uri' => $this->baseUrl]);
        $this->headers = [
            'Auth-API-Token' => $token,
            'Content-Type' => 'application/json',
        ];
        
        $dataDir = __DIR__ . '/../../../data/upload';
        $this->store = new Store('hetzner.dns', $dataDir);
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

            $existing = $this->store->findOneBy(["domainName", "=", $domainName]);
            if (!empty($existing)) {
                $this->store->updateBy(["domainName", "=", $domainName], ["zoneId" => $zoneId]);
            } else {
                $this->store->insert([
                    'domainName' => $domainName,
                    'zoneId' => $zoneId,
                    "dnsRecords" => [],
                ]);
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
        
        $result = $this->store->findOneBy(['domainName', '=', $domainName]);
        if ($result !== null) {
            $zoneId = $result['zoneId'];
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
        
        $result = $this->store->findOneBy(['domainName', '=', $domainName]);
        if ($result !== null) {
            $zoneId = $result['zoneId'];
        }

        try {
            $response = $this->client->request('POST', 'records', [
                'headers' => $this->headers,
                'json' => [
                    'value' => $rrsetData['record_value'][0],
                    'ttl' => $rrsetData['ttl'],
                    'type' => $rrsetData['type'],
                    'name' => $rrsetData['subname'],
                    'zone_id' => $zoneId
                ]
            ]);

            if ($response->getStatusCode() === 201) {
                $body = json_decode($response->getBody()->getContents(), true);
                $recordId = $body['record']['id'] ?? null;
                
                // Check if the DNS record exists in the 'dnsRecords' array
                $key = array_search($recordId, array_column($result['dnsRecords'], 'recordId'));

                $dnsRecord = [
                    'recordId' => $recordId,
                    'recordType' => $rrsetData['type'],
                    'recordName' => $rrsetData['subname'],
                ];

                if ($key !== false) {
                    $result['dnsRecords'][$key] = $dnsRecord;
                } else {
                    $result['dnsRecords'][] = $dnsRecord;
                }
                $this->store->updateById($result['_id'], $result);
                return true;
            } else {
                return false;
            }
        } catch (GuzzleException $e) {
            throw new \FOSSBilling\Exception('Request failed: ' . $e->getMessage());
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
            $result = $this->store->findOneBy(['domainName', '=', $domainName]);
            if (!$result) {
                throw new \FOSSBilling\Exception('Domain not found.');
            }
            $zoneId = $result['zoneId'];

            $recordId = null;

            foreach ($result['dnsRecords'] as $record) {
                if ($record['recordType'] === $type && $record['recordName'] === $subname) {
                    $recordId = $record['recordId'];
                    break;
                }
            }

            if ($recordId === null) {
                throw new \FOSSBilling\Exception('DNS record not found.');
            }

            $response = $this->client->request('PUT', "records/{$recordId}", [
                'headers' => $this->headers,
                'json' => [
                    'value' => $rrsetData['record_value'][0],
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
        }

    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        try {
            $result = $this->store->findOneBy(['domainName', '=', $domainName]);
            if (!$result) {
                throw new \FOSSBilling\Exception('Domain not found.');
            }
            $zoneId = $result['zoneId'];

            $recordId = null;

            foreach ($result['dnsRecords'] as $record) {
                if ($record['recordType'] === $type && $record['recordName'] === $subname) {
                    $recordId = $record['recordId'];
                    break;
                }
            }

            if ($recordId === null) {
                throw new \FOSSBilling\Exception('DNS record not found.');
            }
            
            $response = $this->client->request('DELETE', "records/{$recordId}", [
                'headers' => $this->headers,
            ]);
            
            $filteredRecords = array_filter($result['dnsRecords'], function ($record) use ($recordId) {
                return $record['recordId'] !== $recordId;
            });

            $result['dnsRecords'] = array_values($filteredRecords);
            
            $this->store->updateById($result['_id'], $result);

            if ($response->getStatusCode() === 204) {
                return true;
            } else {
                return false;
            }
        } catch (GuzzleException $e) {
            throw new \FOSSBilling\Exception('Request failed: ' . $e->getMessage());
        }

    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \FOSSBilling\Exception("Not yet implemented");
    }
    
}