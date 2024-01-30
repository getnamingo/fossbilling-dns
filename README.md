# DNS hosting module for FOSSBilling
DNS hosting module for FOSSBilling

## Compatibility

This module is designed for use with the following DNS providers:

- [deSEC](https://desec.io/)

- [Vultr](https://www.vultr.com/)

- [BIND](https://www.isc.org/bind/)

## FOSSBilling Module Installation instructions

### 1. Download and Install FOSSBilling:

Start by downloading the latest version of FOSSBilling from the official website (https://fossbilling.org/). Follow the provided instructions to install it.

### 2. Installation and Configuration of DNS hosting module:

First, download this repository. After successfully downloading the repository, move the `Servicedns` directory into the `[FOSSBilling]/modules` directory.

Go to `[FOSSBilling]/modules/Servicedns/Providers` directory and run the `composer install` command.

### (BIND Module only) 3. Addition of Synchronization Scripts:

The BIND provider has an additional synchronization script which can be found at `[FOSSBilling]/modules/Servicedns/Crons/Bind.php`. It needs to be configured with your BIND installation parameters, so it can generate the zones regularly.

### (BIND Module only) 4. Setting Up the Cron Job:

You need to set up a hourly cron job that runs the sync module. Open crontab using the command `crontab -e` in your terminal.

Add the following cron job:

`0 * * * * php [FOSSBilling]/modules/Servicedns/Crons/Bind.php`

This command schedules the synchronization script to run hourly.

### 5. Activate the DNS hosting module:

Within FOSSBilling, go to **Extensions -> Overview** and activate the `DNS Hosting Product 1.0.0` extension.

Then go to **Products -> Products & Services -> New product** and create a new product of type `Dns`.

Configure your product, and do not forget to select your DNS hosting provider and input the API key on the `Configuration` tab of your product.