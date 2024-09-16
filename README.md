# DNS hosting module for FOSSBilling

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

DNS hosting module for FOSSBilling

## Compatibility

This module is designed for use with the following DNS servers/providers:

- [BIND9](https://www.isc.org/bind/)

- [deSEC](https://desec.io/)

- [DNSimple](https://dnsimple.com/)

- [Hetzner](https://www.hetzner.com/)

- [PowerDNS](https://www.powerdns.com/)

- [Vultr](https://www.vultr.com/)

## FOSSBilling Module Installation instructions

### 1. Download and Install FOSSBilling:

Start by downloading the latest version of FOSSBilling from the official website (https://fossbilling.org/). Follow the provided instructions to install it.

### 2. Installation and Configuration of DNS hosting module:

First, download this repository. After successfully downloading the repository, move the `Servicedns` directory into the `[FOSSBilling]/modules` directory.

Go to `[FOSSBilling]/modules/Servicedns/Providers` directory and run the `composer install` command.

### (BIND9 Module only) 3. Installation of BIND9 API Server:

To use the BIND9 module, you must install the [bind9-api-server](https://github.com/getnamingo/bind9-api-server) on your master BIND server. This API server allows for seamless integration and management of your DNS zones via API.

Make sure to configure the API server according to your BIND installation parameters to ensure proper synchronization of your DNS zones.

### 4. Activate the DNS hosting module:

Within FOSSBilling, go to **Extensions -> Overview** and activate the `DNS Hosting Product 1.0.0` extension.

Then go to **Products -> Products & Services -> New product** and create a new product of type `Dns`.

Configure your product, and do not forget to select your DNS hosting provider and input the API key on the `Configuration` tab of your product.