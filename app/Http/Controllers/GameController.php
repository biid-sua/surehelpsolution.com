<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * "Hands Full", the missed-call game on the public website (D122). The game itself runs in the browser
 * (resources/js/game.js); this only hands it its branding and buttons from config/game.php.
 */
class GameController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(config('game.enabled'), 404);

        $utm = http_build_query(config('game.utm', []));
        $link = function (string $target) use ($utm): string {
            [$name, $fragment] = array_pad(explode('#', $target, 2), 2, null);
            $base = Str::startsWith($name, ['http://', 'https://']) ? $name : (Route::has($name) ? route($name) : url($name));

            return $base.($utm ? (str_contains($base, '?') ? '&' : '?').$utm : '').($fragment ? '#'.$fragment : '');
        };

        $cta = collect(config('game.cta'))->map(fn (array $button) => ['label' => $button['label'], 'url' => $link($button['url'])]);

        return view('game', [
            'game' => config('game'),
            'settings' => [
                'brand' => config('game.brand.name'),
                'receptionist' => config('game.brand.receptionist'),
                'total' => (int) config('game.timing.total'),
                'offerAfter' => (int) config('game.timing.offer_after'),
                'cta' => $cta->values()->all(),
                'share' => route('game'),
            ],
        ]);
    }
}
