Run with PHP 8.3+, PDO SQLite, PDO MySQL, and a FOSSBilling 0.8.7 source checkout:

```bash
cd Servicedns
composer install
FOSSBILLING_ROOT=/path/to/FOSSBilling composer test
```

The regression script uses the real FOSSBilling Product, order and identity models, database adapter, API base and Twig extension, plus the locked PlexDNS service. Provider calls use PlexDNS's Testing provider and an in-memory SQLite database. It covers access control, credential filtering and migration, lifecycle compatibility, zone IDs, local/provider record IDs, missing provider IDs, record data and template parsing. No live provider calls are made; MySQL DDL and browser interaction need a staging installation.
