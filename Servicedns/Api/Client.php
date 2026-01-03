<?php
/**
 * FOSSBilling-DNS module
 *
 * Written in 2024–2025 by Taras Kondratyuk (https://namingo.org)
 * Based on example modules and inspired by existing modules of FOSSBilling
 * (https://www.fossbilling.org) and BoxBilling.
 *
 * @license Apache-2.0
 * @see https://www.apache.org/licenses/LICENSE-2.0
 */

namespace Box\Mod\Servicedns\Api;

class Client extends \Api_Abstract
{
    /**
     * Used to add a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for adding a DNS record.
     */
    public function add($data): bool
    {
        return $this->getService()->addRecord($data);
    }
    
    /**
     * Used to update a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for updating a DNS record.
     */
    public function update($data): bool
    {
        return $this->getService()->updateRecord($data);
    }

    /**
     * Used to delete a DNS record for a specified domain.
     *
     * @param array $data An array containing the identification information of the DNS record to be deleted.
     */
    public function del($data): bool
    {
        return $this->getService()->delRecord($data);
    }
}