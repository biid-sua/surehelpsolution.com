<?php

namespace App\Exceptions;

use RuntimeException;

/** The payment provider couldn't be reached or refused the request (D64). The message is safe to show. */
class BillingUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $declineCode = null)
    {
        parent::__construct($message);
    }
}
