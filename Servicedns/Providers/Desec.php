<?php

namespace Box\Mod\Servicedns\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Desec implements DnsHostingProviderInterface {
    private $baseUrl = "https://desec.io/api/v1/domains/";
    private $client;
    private $headers;

    public function __construct($token) {
        if (empty($token)) {
            throw new \FOSSBilling\Exception("API token cannot be empty");
        }

        $this->client = new Client(['base_uri' => $this->baseUrl]);
        $this->headers = [
            'Authorization' => 'Token ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \FOSSBilling\Exception("Domain name cannot be empty");
        }

        $response = $this->client->request('POST', '', [
            'headers' => $this->headers,
            'json' => ['name' => $domainName]
        ]);

        return json_decode($response->getBody(), true);
    }

    public function listDomains() {
        $response = $this->client->request('GET', '', ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function getDomain($domainName) {
        $response = $this->client->request('GET', $domainName . '/', ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function getResponsibleDomain($qname) {
        $response = $this->client->request('GET', '?owns_qname=' . $qname, ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function exportDomainAsZonefile($domainName) {
        $response = $this->client->request('GET', $domainName . "/zonefile/", ['headers' => $this->headers]);
        return $response->getBody();
    }

    public function deleteDomain($domainName) {
        $response = $this->client->request('DELETE', $domainName . "/", ['headers' => $this->headers]);
        return $response->getStatusCode() === 204;
    }
    
    public function createRRset($domainName, $rrsetData) {
        $response = $this->client->request('POST', $domainName . '/rrsets/', [
            'headers' => $this->headers,
            'json' => $rrsetData
        ]);

        return json_decode($response->getBody(), true);
    }

    public function createBulkRRsets($domainName, $rrsetDataArray) {
        $response = $this->client->request('POST', $domainName . '/rrsets/', [
            'headers' => $this->headers,
            'json' => $rrsetDataArray
        ]);

        return json_decode($response->getBody(), true);
    }

    public function retrieveAllRRsets($domainName) {
        $response = $this->client->request('GET', $domainName . '/rrsets/', ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function retrieveSpecificRRset($domainName, $subname, $type) {
        $response = $this->client->request('GET', $domainName . '/rrsets/' . $subname . '/' . $type . '/', ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData) {
        $response = $this->client->request('PATCH', $domainName . '/rrsets/' . $subname . '/' . $type . '/', [
            'headers' => $this->headers,
            'json' => $rrsetData
        ]);

        return json_decode($response->getBody(), true);
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        $response = $this->client->request('PUT', $domainName . '/rrsets/', [
            'headers' => $this->headers,
            'json' => $rrsetDataArray
        ]);

        return json_decode($response->getBody(), true);
    }

    public function deleteRRset($domainName, $subname, $type) {
        $response = $this->client->request('DELETE', $domainName . '/rrsets/' . $subname . '/' . $type . '/', ['headers' => $this->headers]);
        return $response->getStatusCode() === 204;
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        $response = $this->client->request('PATCH', $domainName . '/rrsets/', [
            'headers' => $this->headers,
            'json' => $rrsetDataArray
        ]);

        return $response->getStatusCode() === 204;
    }
    
}