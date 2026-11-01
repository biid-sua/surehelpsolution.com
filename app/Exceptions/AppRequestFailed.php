<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A connected app (D110) refused a request. `code` is the app's own error code (QuickBooks: 6240
 * for a name that's already used).
 */
class AppRequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $appCode = '')
    {
        parent::__construct($message);
    }
}
