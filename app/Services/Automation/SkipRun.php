<?php

namespace App\Services\Automation;

/** An automation run that doesn't apply (no email address, no consent…): recorded as skipped, never retried. */
class SkipRun extends \RuntimeException {}
