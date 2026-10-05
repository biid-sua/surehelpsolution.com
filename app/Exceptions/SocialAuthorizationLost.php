<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The network no longer accepts our access (revoked, expired, permission removed): the business must reconnect.
 */
class SocialAuthorizationLost extends RuntimeException {}
