# DNS hosting module for FOSSBilling
DNS hosting module for FOSSBilling

## Compatibility

This module is designed for use with the following DNS providers:

- [deSEC](https://desec.io/)

## FOSSBilling Module Installation instructions

### 1. Download and Install FOSSBilling:

Start by downloading the latest version of FOSSBilling from the official website (https://fossbilling.org/). Follow the provided instructions to install it.

### 2. Installation and Configuration of DNS hosting module:

First, download this repository. After successfully downloading the repository, move the `Servicedns` directory into the `[FOSSBilling]/modules` directory.

### 3. Addition of Synchronization Scripts:

**not yet implemented**

### 4. Setting Up the Cron Job:

**not yet implemented**

### 5. Activate the DNS hosting module:

Within FOSSBilling, go to **Extensions -> Overview** and activate the `DNS Hosting Product 1.0.0` extension.

Then go to **Products -> Products & Services -> New product** and create a new product of type `Dns`.

Configure your product, and do not forget to select your DNS hosting provider and input the API key on the `Configuration` tab of your product.