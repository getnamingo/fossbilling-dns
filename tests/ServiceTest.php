<?php

declare(strict_types=1);

// Uses FOSSBilling's actual 0.8.7 Product, legacy order/identity models, DB adapter and API base.
define('PLEX_TABLE_ZONES', 'service_dns');
define('PLEX_TABLE_RECORDS', 'service_dns_records');
define('DEBUG', false);
require dirname(__DIR__) . '/Servicedns/vendor/autoload.php';
$core = getenv('FOSSBILLING_ROOT');
if (!$core || !is_file($core . '/src/library/FOSSBilling/Api/AbstractApi.php')) {
    throw new RuntimeException('Set FOSSBILLING_ROOT to a checkout of FOSSBilling 0.8.7.');
}
spl_autoload_register(function (string $class) use ($core): void {
    if (str_starts_with($class, 'Box\\Mod\\Servicedns\\')) {
        $file = dirname(__DIR__) . '/Servicedns/' . str_replace('\\', '/', substr($class, 19)) . '.php';
    } elseif (str_starts_with($class, 'Box\\Mod\\')) {
        $file = $core . '/src/modules/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    } else {
        $file = $core . '/src/library/' . str_replace(['\\', '_'], '/', $class) . '.php';
    }
    if (is_file($file)) require_once $file;
});
function __trans(string $message, ?array $params = null): string { return strtr($message, $params ?? []); }
$configFile = tempnam(sys_get_temp_dir(), 'dns-test-config');
file_put_contents($configFile, '<?php return ["debug_and_monitoring" => ["log_stacktrace" => false]];');
define('PATH_CONFIG', $configFile);
register_shutdown_function(fn() => unlink($configFile));
require dirname(__DIR__) . '/Servicedns/Service.php';

use RedBeanPHP\R;
use PlexDNS\Service as PlexService;
use Box\Mod\Servicedns\Service;

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $call, string $message): void {
    try { $call(); } catch (FOSSBilling\InformationException $error) { return; }
    throw new RuntimeException($message);
}
// Turn notices/warnings into failures so missing data and ORM property mismatches are visible.
set_error_handler(function ($level, $message, $file, $line) {
    if (!(error_reporting() & $level)) return false;
    throw new ErrorException($message, 0, $level, $file, $line);
});

R::setup('sqlite::memory:');
R::freeze(true);
RedBeanPHP\Util\DispenseHelper::setEnforceNamingPolicy(false);
R::useWriterCache(false);
$pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
$pdo->exec('PRAGMA foreign_keys = ON');
(new PlexService($pdo))->install();
$pdo->exec('CREATE TABLE client_order (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, product_id INTEGER, service_id INTEGER, service_type TEXT, status TEXT, title TEXT, config TEXT, activated_at TEXT)');
$pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY, type TEXT)');
$pdo->exec('CREATE TABLE cart_product (id INTEGER PRIMARY KEY, product_id INTEGER, config TEXT)');
$db = new Box_Database();
$db->setDataMapper(new R());

class RecordingPlex extends PlexService {
    public array $calls = [];
    public bool $failDelete = false;
    public function createDomain(array $order): array {
        $this->calls[] = ['create', $order];
        $config = json_decode($order['config'], true);
        $config['provider'] = 'Testing';
        $order['config'] = json_encode($config);
        $result = parent::createDomain($order);
        // Simulate a provider-created zone ID in the row PlexDNS upserts.
        $this->db->exec("UPDATE service_dns SET zoneId = 'bunny-zone-42'");
        return $result;
    }
    public function addRecord(array $data): int {
        $this->calls[] = ['add', $data];
        return parent::addRecord(array_replace($data, ['provider' => 'Testing']));
    }
    public function updateRecord(array $data): bool {
        $this->calls[] = ['update', $data];
        return parent::updateRecord(array_replace($data, ['provider' => 'Testing']));
    }
    public function delRecord(array $data): bool {
        $this->calls[] = ['delete', $data];
        return parent::delRecord(array_replace($data, ['provider' => 'Testing']));
    }
    public function deleteDomain(array $order): void {
        $this->calls[] = ['deleteDomain', $order];
        if ($this->failDelete) throw new RuntimeException('Provider request failed');
        $config = json_decode($order['config'], true);
        $config['provider'] = 'Testing';
        parent::deleteDomain(['config' => json_encode($config)]);
    }
}
class TestService extends Service {
    public RecordingPlex $backend;
    protected function plex(): PlexService { return $this->backend; }
}

$product = new Box\Mod\Product\Entity\Product();
$product->setType('dns');
$product->setStatus('enabled');
$private = ['provider' => 'Bunny', 'apikey' => 'private-token', 'ns1' => 'ns1.example.net',
    'cloudns_auth_id' => '42', 'cloudns_auth_password' => 'private-password',
    'bindip' => '192.0.2.1', 'powerdnsip' => '192.0.2.2', 'pdns_master_ip' => '192.0.2.3',
    'apikey_ns2' => 'secondary-secret', 'bindip_ns2' => '192.0.2.4', 'soaemail' => 'hostmaster.example.net'];
$product->setConfig(json_encode($private));
$products = new class($product) {
    public function __construct(public $product) {}
    public function findProductById(int $id) { return $this->product; }
    public function findOneActiveById(int $id) { return $id === 1 && $this->product->getStatus() === 'enabled' ? $this->product : null; }
};
$staff = new class {
    public bool $allow = true;
    public int $checks = 0;
    public function checkPermissionsAndThrowException($module, $key, $constraint, $identity): void {
        $this->checks++;
        expect($module === 'servicedns' && $key === 'manage', 'Correct staff permission.');
        if (!$this->allow) throw new FOSSBilling\InformationException('Permission denied');
    }
};
$di = new Pimple\Container(['db' => $db, 'pdo' => $pdo]);
$orderService = new Box\Mod\Order\Service();
$orderService->setDi($di);
$di['mod_service'] = $di->protect(fn($name) => match (strtolower($name)) { 'order' => $orderService, 'product' => $products, 'staff' => $staff });
$service = new TestService();
$service->setDi($di);
$service->backend = new RecordingPlex($pdo);

// Client config cannot override connection endpoints, credentials or the trusted provider.
$public = $service->attachOrderConfig($product, ['domain_name' => 'EXAMPLE.COM.', 'provider' => 'Bind', 'apikey' => 'attacker', 'bindip' => '127.0.0.1', '_provisioned' => true]);
expect($public === ['provider' => 'Bunny', 'ns1' => 'ns1.example.net', 'domain_name' => 'example.com'], 'Safe cart config with 0.8.7 Product.');
rejects(fn() => $service->attachOrderConfig($product, ['domain_name' => '../bad']), 'Invalid domain rejected.');
rejects(fn() => $service->attachOrderConfig($product, ['domain_name' => []]), 'Non-string domain rejected.');
$longDomain = str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.example';
expect(strlen($service->attachOrderConfig($product, ['domain_name' => $longDomain])['domain_name']) > 75, 'Long valid domains accepted.');

$order = $db->dispense('ClientOrder');
expect($order instanceof Model_ClientOrder && !$order instanceof RedBeanPHP\OODBBean, 'Core passes a boxed legacy order, not a bean.');
$order->client_id = 7; $order->product_id = 1; $order->service_type = 'dns'; $order->status = 'active';
$order->config = json_encode($public); $order->activated_at = '2026-09-19 12:00:00';
$db->store($order);
$model = $service->create($order);
$order->service_id = $model->id; $db->store($order);
expect(!str_contains($order->config, 'private-'), 'Order never stores provider secrets.');
expect(str_contains($model->config, 'private-token'), 'Private service snapshot retained.');
$service->activate($order, $model);
expect($model->export()['zoneId'] === 'bunny-zone-42' && $db->getCell('SELECT zoneId FROM service_dns') === 'bunny-zone-42', 'Activation preserves provider zone ID.');
$config = json_decode($service->backend->calls[0][1]['config'], true);
foreach ($private as $key => $value) expect($config[$key] === $value, 'Activation preserves ' . $key);
$count = count($service->backend->calls);
$service->activate($order, $model);
expect(count($service->backend->calls) === $count, 'Activation retry is idempotent after success.');
rejects(fn() => $service->create($order), 'Duplicate zone blocked.');
expect(!str_contains(json_encode($service->toApiArray($model)), 'private-'), 'Service API cannot expose secrets.');

// Exercise the actual core API base and client identity, without any browser session.
$clientBean = new RedBeanPHP\OODBBean(); $clientBean->initializeForDispense('client'); $clientBean->id = 7;
$client = new Model_Client(); $client->loadBean($clientBean);
$otherBean = clone $clientBean; $otherBean->id = 8;
$other = new Model_Client(); $other->loadBean($otherBean);
$api = new Box\Mod\Servicedns\Api\Client(); $api->setService($service); $api->setDi($di); $api->setIdentity($client);
$data = ['order_id' => $order->id, 'record_name' => 'www', 'record_type' => 'A', 'record_value' => '192.0.2.1', 'record_ttl' => 300];
expect($api->add($data), 'Authenticated client API add works.');
$recordId = (int)$pdo->query('SELECT id FROM service_dns_records')->fetchColumn();
// A provider ID is deliberately different from the local ID.
$pdo->exec("UPDATE service_dns_records SET recordId = 'provider-uuid-42'");
expect($api->update(['record_id' => $recordId, 'record_value' => '192.0.2.2', 'record_name' => 'forged', 'old_value' => 'forged'] + $data), 'Update by LOCAL ID.');
$last = end($service->backend->calls)[1];
expect($last['record_id'] === $recordId && $last['record_name'] === 'www' && $last['old_value'] === '192.0.2.1', 'Canonical selected record and saved old value.');
expect($pdo->query('SELECT value FROM service_dns_records')->fetchColumn() === '192.0.2.2', 'PlexDNS updated correct local row.');
expect($api->del(['record_id' => $recordId, 'record_value' => 'unsaved edit'] + $data), 'Deletion ignores unsaved text.');
expect((int)$pdo->query('SELECT COUNT(*) FROM service_dns_records')->fetchColumn() === 0, 'PlexDNS removed local row.');
$api->add($data);
$recordId = (int)$pdo->query('SELECT id FROM service_dns_records')->fetchColumn();
$pdo->exec('UPDATE service_dns_records SET recordId = NULL');
expect($api->update(['record_id' => $recordId, 'record_value' => '192.0.2.3'] + $data), 'Providers without IDs remain editable.');

$api->setIdentity($other);
foreach (['add', 'update', 'del'] as $method) rejects(fn() => $api->$method(['record_id' => $recordId] + $data), 'Other client blocked: ' . $method);
$api->setIdentity(null);
rejects(fn() => $api->add($data), 'Missing identity does not become admin.');
$api->setIdentity($client);
$order->status = 'suspended'; $db->store($order);
rejects(fn() => $api->add($data), 'Suspended order API edits blocked.');
$order->status = 'active'; $order->service_type = 'hosting'; $db->store($order);
rejects(fn() => $api->add($data), 'Wrong service type blocked.');
$order->service_type = 'dns'; $db->store($order);
$pdo->exec("INSERT INTO service_dns (client_id, domain_name, config) VALUES (8, 'other.example', '{}')");
$otherZone = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO service_dns_records (domain_id, type, host, value) VALUES ($otherZone, 'A', 'www', '192.0.2.9')");
$foreignRecordId = (int)$pdo->lastInsertId();
rejects(fn() => $api->del(['record_id' => $foreignRecordId] + $data), 'Cross-zone record IDs blocked.');

foreach ([['record_ttl' => -1], ['record_ttl' => 'abc'], ['record_name' => 'outside.example.'], ['record_type' => '']] as $invalid) {
    rejects(fn() => $api->add($invalid + $data), 'Invalid record fields rejected.');
}
$api->add(['record_type' => 'TXT', 'record_name' => '@', 'record_value' => '  keep "quotes" and spaces  '] + $data);
$last = end($service->backend->calls)[1];
expect($last['record_name'] === '' && $last['record_value'] === '  keep "quotes" and spaces  ', 'JSON TXT content is preserved.');
$api->add(['record_type' => 'MX', 'record_value' => 'mail.example.com', 'record_priority' => '20'] + $data);
$mxId = (int)$pdo->lastInsertId();
$api->update(['record_id' => $mxId, 'record_value' => 'mail2.example.com', 'record_priority' => '30'] + $data);
expect((int)$pdo->query("SELECT priority FROM service_dns_records WHERE id = $mxId")->fetchColumn() === 30, 'MX priority changes persist.');

// Staff permission uses core AbstractApi::checkPermissions with the API identity.
$adminBean = new RedBeanPHP\OODBBean(); $adminBean->initializeForDispense('admin'); $adminBean->id = 1;
$admin = new Model_Admin(); $admin->loadBean($adminBean);
$adminApi = new Box\Mod\Servicedns\Api\Admin(); $adminApi->setService($service); $adminApi->setDi($di); $adminApi->setIdentity($admin);
$staff->allow = false;
rejects(fn() => $adminApi->add($data), 'Staff permission enforced.');
$staff->allow = true;
expect($adminApi->add($data) && $staff->checks === 2, 'Authorized staff can manage DNS.');

$guestApi = new Box\Mod\Servicedns\Api\Guest(); $guestApi->setService($service); $guestApi->setDi($di);
expect($guestApi->nameservers(['product_id' => 1]) === ['ns1.example.net'], 'Guest sees only nameservers.');
rejects(fn() => $guestApi->nameservers(['product_id' => 2]), 'Unknown product not exposed.');
$product->setStatus('disabled');
rejects(fn() => $guestApi->nameservers(['product_id' => 1]), 'Disabled product not exposed.');
$product->setStatus('enabled');

// Existing-order/cart migration preserves private credentials and record IDs; can run twice.
$oldConfig = json_encode($private + ['domain_name' => 'example.com']);
$pdo->prepare('UPDATE client_order SET config = ? WHERE id = ?')->execute([$oldConfig, $order->id]);
$pdo->prepare('UPDATE service_dns SET config = ? WHERE id = ?')->execute([json_encode(['provider' => 'Bunny']), $model->id]);
$pdo->exec("INSERT INTO product VALUES (1, 'dns')");
$pdo->prepare('INSERT INTO cart_product VALUES (1, 1, ?)')->execute([$oldConfig]);
$migrate = new ReflectionMethod(Service::class, 'migrate');
$migrate->invoke($service); $migrate->invoke($service);
expect(!str_contains($pdo->query('SELECT config FROM client_order')->fetchColumn(), 'private-'), 'Existing order scrubbed.');
expect(!str_contains($pdo->query('SELECT config FROM cart_product')->fetchColumn(), 'private-'), 'Existing cart scrubbed.');
expect(str_contains($pdo->query('SELECT config FROM service_dns WHERE id = 1')->fetchColumn(), 'private-token'), 'Existing service credentials retained.');
expect((int)$pdo->query('SELECT COUNT(*) FROM service_dns_records')->fetchColumn() > 0, 'Migration preserves DNS records.');

$count = count($service->backend->calls);
$service->delete($order, null);
expect(count($service->backend->calls) === $count, 'Order without service cannot delete a remote zone.');
$service->backend->failDelete = true;
try { $service->delete($order, $model); throw new LogicException('Expected provider failure'); } catch (RuntimeException $e) {}
expect($db->findOne('service_dns', 'id = ?', [$model->id]) !== null, 'Provider failure preserves local service.');
$service->backend->failDelete = false;
$service->delete($order, $model);
expect($db->findOne('service_dns', 'id = ?', [$model->id]) === null, 'Successful provider deletion removes service.');

// Parse every shipped Twig template with the actual 0.8.7 API form extension.
$loader = new Twig\Loader\FilesystemLoader([dirname(__DIR__) . '/Servicedns/templates/admin', dirname(__DIR__) . '/Servicedns/templates/client', dirname(__DIR__) . '/Servicedns/templates/email']);
$twig = new Twig\Environment($loader);
$twig->addExtension(new Twig\Extension\AttributeExtension(FOSSBilling\Twig\Extension\ApiExtension::class));
foreach (['trans', 'url', 'format_date'] as $name) $twig->addFilter(new Twig\TwigFilter($name, fn($value) => $value));
foreach (glob(dirname(__DIR__) . '/Servicedns/templates/*/*.twig') as $file) $twig->parse($twig->tokenize(new Twig\Source(file_get_contents($file), basename($file))));

echo "DNS module regression checks passed against FOSSBilling 0.8.7 and locked PlexDNS.\n";
