# DNS hosting module for FOSSBilling

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

DNS hosting module for FOSSBilling

## Supported Providers

FOSSBilling DNS uses **Cardo DNS 1.1+** for provider integration. See the authoritative [Cardo DNS supported providers table](https://github.com/getnamingo/cardo-dns#supported-providers) for provider availability, credentials, requirements, and DNSSEC support.

The FOSSBilling product settings expose DigitalOcean, Gandi LiveDNS, Scaleway, and all previously available Cardo DNS providers.

## Installation

> [!WARNING]
> **fossbilling-dns v1.3.0** requires **FOSSBilling v0.8.7+** and **PHP 8.3+**.
>
> If you are using **FOSSBilling v0.7.2**, please use **fossbilling-dns v1.1.2** instead.

### 1. Download and Install FOSSBilling:

Start by downloading the latest version of FOSSBilling from the official website (https://fossbilling.org/). Follow the provided instructions to install it.

### 2. Installation and Configuration of DNS hosting module:

First, download this repository. After successfully downloading the repository, move the `Servicedns` directory into the `[FOSSBilling]/modules` directory.

Release archives include dependencies. When installing from source, run `composer update --no-dev` inside `Servicedns` first. Cardo DNS keeps the Composer package name `namingo/plexdns` for backward compatibility; this module requires `^1.1.0` and uses the native `Namingo\\Cardo\\DNS` namespace.

### (BIND9 Module only) 3. Installation of BIND9 API Server:

To use the BIND9 module, you must install the [bind9-api](https://github.com/getnamingo/bind9-api) on your master BIND server. This API allows for seamless integration and management of your DNS zones via API.

Make sure to configure the API server according to your BIND installation parameters to ensure proper synchronization of your DNS zones.

### 4. Activate the DNS hosting module:

Within FOSSBilling, go to **Extensions -> Overview** and activate the `DNS Hosting Product` extension.

Then go to **Products -> Products & Services -> New product** and create a new product of type `Dns`.

Configure your product, and select the DNS hosting provider plus its credentials on the `Configuration` tab.

Provider-specific settings:
- **AnycastDNS:** Server ID is optional and defaults to `0`.
- **ClouDNS:** configure the authentication ID and authentication password.
- **Scaleway:** **Project ID** is required; **Parent Domain** is optional and normally left empty for root-zone hosting.
- **Gandi LiveDNS:** use the normal API key field for the token; **Sharing ID** is optional and **Bearer** is the recommended authentication scheme.
- **DigitalOcean:** only the normal API key field is required.

Provider credentials remain in the private product/service configuration and are not copied into client-visible cart or order configuration.

## Upgrade

To upgrade the DNS hosting module to the latest version, download the newest release and replace the existing module files.

### Manual upgrade

1. Download the **latest release** archive from the repository.
2. Extract the archive to a temporary directory.
3. Locate the `Servicedns` directory inside the extracted release.
4. Copy the `Servicedns` directory into `[FOSSBilling]/modules/`, **overwriting** the existing `Servicedns` directory.
5. From the FOSSBilling directory, run `php modules/Servicedns/upgrade.php` before reopening client access. This preserves existing provider credentials in private service configuration, removes them from client-visible orders and carts, and widens the domain-name column. Back up your database first; the migration can safely be run again.
6. Clear FOSSBilling cache if applicable and reload the admin panel.

Earlier versions exposed provider credentials through order/service configuration. Rotate credentials used by those versions and update both the product configuration and existing private `service_dns.config` snapshots. Updating a product alone does not change existing services.

### Upgrade via console

From your server:

```bash
cd /tmp
wget https://github.com/getnamingo/fossbilling-dns/releases/download/v1.3.0/fossbilling-dns-v1.3.0.tar.gz
tar xzf fossbilling-dns-v1.3.0.tar.gz
cd fossbilling-dns-v1.3.0
cp -a Servicedns/. /path/to/FOSSBilling/modules/Servicedns/
cd /path/to/FOSSBilling
php modules/Servicedns/upgrade.php
```

After upgrading, log in to the FOSSBilling admin panel and verify that the module version is updated under Extensions -> Overview.

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/fossbilling-dns/issues) section of our GitHub repository.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Support This Project

If you find DNS hosting module for FOSSBilling useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

DNS hosting module for FOSSBilling is licensed under the Apache-2.0 license.
