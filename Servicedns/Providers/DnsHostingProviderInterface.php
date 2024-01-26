<?php

namespace Box\Mod\Servicedns\Providers;

interface DnsHostingProviderInterface {
    public function createDomain($domain);
    public function deleteDomain($domain);
    public function addRecord($domain, $record);
    public function deleteRecord($domain, $recordId);
    public function modifyRecord($domain, $recordId, $newRecord);
}
