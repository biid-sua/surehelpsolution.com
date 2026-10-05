<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The network refused this post's content (too long, duplicate, policy). Retrying won't help: a person must change it.
 */
class SocialPostRejected extends RuntimeException {}
