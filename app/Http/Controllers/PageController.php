<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * The site's menu pages (products, solutions, resources, company), in the existing site design.
 * Content lives in config/pages.php.
 */
class PageController extends Controller
{
    public function show(string $page): View
    {
        $pages = collect(config('pages'));
        $current = $pages->get($page);
        abort_if($current === null, 404);

        return view('pages.show', [
            'page' => $current,
            'related' => $pages->except($page)->filter(fn (array $p) => $p['group'] === $current['group'])->take(12),
        ]);
    }
}
