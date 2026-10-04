<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The event was changed in the external calendar since we wrote it; we don't overwrite it (spec §16).
 */
class CalendarEventChanged extends RuntimeException {}
