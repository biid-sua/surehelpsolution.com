<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use RuntimeException;

/** Someone tried to add a customer whose phone number another customer already has. */
class DuplicateCustomer extends RuntimeException
{
    public function __construct(public readonly Customer $existing)
    {
        parent::__construct("{$existing->fullName()} already has this number.");
    }
}
