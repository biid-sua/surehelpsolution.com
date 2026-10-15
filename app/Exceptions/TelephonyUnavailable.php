<?php

namespace App\Exceptions;

use RuntimeException;

/** The telephony vendor couldn't be reached or refused the request (D60). The message is safe to show. */
class TelephonyUnavailable extends RuntimeException {}
