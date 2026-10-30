<?php

namespace App\Livewire\Concerns;

/**
 * The social screens serve two portals (D104): a business's own (`app.social.*`) and SureHelp's own
 * accounts in the admin console (`admin.social.*`). Links and redirects go through socialRoute().
 */
trait SocialPortal
{
    public function socialPortal(): string
    {
        return 'app';
    }

    /** @param  mixed  $parameters */
    public function socialRoute(string $name, $parameters = []): string
    {
        return route($this->socialPortal().'.social.'.$name, $parameters);
    }
}
