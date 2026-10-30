<?php

namespace App\Livewire\Admin\Social;

use App\Livewire\Client\Social\Index as BusinessScreen;
use App\Livewire\Concerns\HouseSocialAdmin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * Posts, calendar and approvals for SureHelp's own accounts (D104): the business screen, run for SureHelp's internal organization.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('SureHelp social media')]
class Index extends BusinessScreen
{
    use HouseSocialAdmin;
}
