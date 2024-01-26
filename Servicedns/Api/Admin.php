<?php
/**
 * Copyright 2022-2023 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace Box\Mod\Servicedns\Api;

class Admin extends \Api_Abstract
{
    /**
     * Update an API key. Can be used to change the config, but not to reset / regenerate the key itself.
     *
     * @param array $data - An associative array
     *                    - int 'order_id' (required) The order ID of the API key to reset.
     *                    - array 'config' (optional) The new configuration for the API key. Overrides the previous one, so you must send the complete config rather than only the parameters you want to change.
     */
    public function update($data): bool
    {
        return $this->getService()->updateDomain($data);
    }

    /**
     * Used to add a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for adding a DNS record.
     *                    The 'domain_name' must be provided:
     *                    - string 'domain_name' The name of the domain to which the DNS record will be added.
     */
    public function add($data): bool
    {
        return $this->getService()->addRecord($data);
    }
    
    /**
     * Used to delete a DNS record for a specified domain.
     *
     * @param array $data An array containing the identification information of the DNS record to be deleted.
     *                    One or more of the following parameters must be provided:
     *                    - int 'record_id' (optional) The unique ID of the DNS record to delete.
     *                    - string 'domain_name' (optional) The domain name associated with the DNS record to delete.
     *                    - string 'record_type' (optional) The type of the DNS record (e.g., A, MX, CNAME) to delete.
     *                    - string 'host' (optional) The host name for the DNS record to delete.
     */
    public function del($data): bool
    {
        return $this->getService()->delRecord($data);
    }
}
