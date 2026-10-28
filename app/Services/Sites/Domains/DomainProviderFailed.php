<?php

namespace App\Services\Sites\Domains;

use RuntimeException;

/** The certificate provider couldn't be reached or refused the domain. */
class DomainProviderFailed extends RuntimeException {}
