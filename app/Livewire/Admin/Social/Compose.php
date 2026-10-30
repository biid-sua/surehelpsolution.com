<?php

namespace App\Livewire\Admin\Social;

use App\Livewire\Client\Social\Compose as BusinessScreen;
use App\Livewire\Concerns\HouseSocialAdmin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * Write or edit one of SureHelp's own posts (D104): the business screen, run for SureHelp's internal organization.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('SureHelp social post')]
class Compose extends BusinessScreen
{
    use HouseSocialAdmin;
}
