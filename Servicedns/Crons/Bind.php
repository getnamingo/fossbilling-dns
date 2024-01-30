<?php
require_once __DIR__ . '/../Providers/vendor/autoload.php';

$config = include __DIR__ . '/../../../config.php';
$c = $config["db"];

use Badcow\DNS\Zone;
use Badcow\DNS\Rdata\Factory;
use Badcow\DNS\ResourceRecord;
use Badcow\DNS\Classes;
use Badcow\DNS\AlignedBuilder;

try {
    $dsn = $c["type"] . ":host=" . $c["host"] . ";port=" . $c["port"] . ";dbname=" . $c["name"];
    $pdo = new PDO($dsn, $c["user"], $c["password"]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch domain names and IDs from service_dns table
    $domainsStmt = $pdo->query("SELECT id, domain_name FROM service_dns");
    $domains = $domainsStmt->fetchAll(PDO::FETCH_ASSOC);
    $timestamp = time();

    foreach ($domains as $domain) {
        $domainName = $domain['domain_name'];
        $domainId = $domain['id'];

        $stmt = $pdo->prepare("SELECT * FROM service_dns_records WHERE domain_id = :domainId");
        $stmt->execute(['domainId' => $domainId]);

        $zone = new Zone($domainName.'.');
        $zone->setDefaultTtl(3600);
        
        $soa = new ResourceRecord;
        $soa->setName('@');
        $soa->setClass(Classes::INTERNET);
        $soa->setRdata(Factory::Soa(
            'example.com.',
            'post.example.com.',
            $timestamp,
            3600,
            14400,
            604800,
            3600
        ));
        $zone->addResourceRecord($soa);

        $ns1 = new ResourceRecord;
        $ns1->setName('@');
        $ns1->setClass(Classes::INTERNET);
        $ns1->setRdata(Factory::Ns('ns1.nameserver.com.'));
        $zone->addResourceRecord($ns1);

        $ns2 = new ResourceRecord;
        $ns2->setName('@');
        $ns2->setClass(Classes::INTERNET);
        $ns2->setRdata(Factory::Ns('ns2.nameserver.com.'));
        $zone->addResourceRecord($ns2);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $recordType = $row['type'];
            $host = $row['host'];
            $value = $row['value'];
            $ttl = $row['ttl'] ?? 3600; // Default TTL

            $record = new ResourceRecord();
            $record->setName($host);
            $record->setTtl($ttl);

            switch ($recordType) {
                case 'A':
                    $record->setRdata(Factory::A($value));
                    break;
                case 'AAAA':
                    $record->setRdata(Factory::Aaaa($value));
                    break;
                case 'MX':
                    $priority = $row['priority'] ?? 0;
                    $record->setRdata(Factory::Mx($priority, $value));
                    break;
                case 'CNAME':
                    $record->setRdata(Factory::Cname($value));
                    break;
                case 'TXT':
                    $formattedValue = trim($value, '"');
                    $record->setRdata(Factory::Txt($formattedValue));
                    break;
                case 'SPF':
                    $formattedValue = trim($value, '"');
                    $record->setRdata(Factory::Spf($formattedValue));
                    break;
                case 'DS':
                    // DS record typically requires key tag, algorithm, digest type, and digest
                    // Assuming these are provided in a formatted string or individual columns
                    // $value format should be "keyTag algorithm digestType digest"
                    list($keyTag, $algorithm, $digestType, $digest) = explode(' ', $value);
                    $record->setRdata(Factory::Ds($keyTag, $algorithm, $digestType, $digest));
                    break;
                // ... Other record types
            }

            $zone->addResourceRecord($record);
        }

        // Generate zone file content for each domain
        $builder = new AlignedBuilder();
        $zoneFileContent = $builder->build($zone);

        // Generate zone for each domain and reload BIND
        file_put_contents("/var/lib/bind/db.$domainName", $zoneFileContent);
        
        exec("rndc reload {$domainName}.", $output, $return_var);
        exec("rndc notify {$domainName}.", $output, $return_var);
    }

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
