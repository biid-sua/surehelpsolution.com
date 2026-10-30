<?php

namespace App\Livewire\Admin\Social;

use App\Livewire\Client\Social\Media as BusinessScreen;
use App\Livewire\Concerns\HouseSocialAdmin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * Pictures and videos SureHelp's posts use (D104): the business screen, run for SureHelp's internal organization.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('SureHelp media library')]
class Media extends BusinessScreen
{
    use HouseSocialAdmin;
}
