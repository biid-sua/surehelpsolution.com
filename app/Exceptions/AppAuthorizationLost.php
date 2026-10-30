<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A connected app (D110) no longer accepts our access: the business disconnected us there, or the
 * access ran out. They must connect again.
 */
class AppAuthorizationLost extends RuntimeException {}
