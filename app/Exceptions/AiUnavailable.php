<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The AI vendor couldn't answer (network, rate limit, outage, bad key). The conversation goes to a person.
 */
class AiUnavailable extends RuntimeException {}
