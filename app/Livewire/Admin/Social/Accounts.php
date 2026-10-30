<?php

namespace App\Livewire\Admin\Social;

use App\Livewire\Client\Social\Accounts as BusinessScreen;
use App\Livewire\Concerns\HouseSocialAdmin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * SureHelp's own connected accounts (D104): the business screen, run for SureHelp's internal organization.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('SureHelp social accounts')]
class Accounts extends BusinessScreen
{
    use HouseSocialAdmin;
}
