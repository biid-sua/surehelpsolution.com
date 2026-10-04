<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The business revoked our access or the refresh token expired: they must reconnect (CAL-06).
 */
class CalendarAuthorizationLost extends RuntimeException {}
