<?php

/*
 * FOSSBilling-DNS module
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the Apache License, Version 2.0.
 * May contain portions of FOSSBilling sample modules, also licensed under Apache-2.0.
 * See the LICENSE file for the full license text.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Servicedns\Api;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Used to add a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for adding a DNS record.
     */
    public function add($data): bool
    {
        $this->checkPermissions('servicedns', 'manage');
        return $this->getService()->addRecord($data, $this->getIdentity());
    }
    
    /**
     * Used to update a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for updating a DNS record.
     */
    public function update($data): bool
    {
        $this->checkPermissions('servicedns', 'manage');
        return $this->getService()->updateRecord($data, $this->getIdentity());
    }

    /**
     * Used to delete a DNS record for a specified domain.
     *
     * @param array $data An array containing the identification information of the DNS record to be deleted.
     */
    public function del($data): bool
    {
        $this->checkPermissions('servicedns', 'manage');
        return $this->getService()->delRecord($data, $this->getIdentity());
    }
}