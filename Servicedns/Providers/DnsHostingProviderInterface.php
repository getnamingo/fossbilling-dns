<?php

namespace Box\Mod\Servicedns\Providers;

require_once __DIR__ . '/vendor/autoload.php';

interface DnsHostingProviderInterface {
    public function createDomain($domain);
    public function listDomains();
    public function getDomain($domain);
    public function getResponsibleDomain($qname);
    public function exportDomainAsZonefile($domain);
    public function deleteDomain($domain);
    public function createRRset($domain, $rrsetData);
    public function createBulkRRsets($domain, $rrsetDataArray);
    public function retrieveAllRRsets($domain);
    public function retrieveSpecificRRset($domain, $subname, $type);
    public function modifyRRset($domain, $subname, $type, $rrsetData);
    public function modifyBulkRRsets($domain, $rrsetDataArray);
    public function deleteRRset($domain, $subname, $type, $value);
    public function deleteBulkRRsets($domain, $rrsetDataArray);
}
