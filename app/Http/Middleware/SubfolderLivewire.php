<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire tells the browser to send component updates to a root-relative "/livewire/update".
 * When the app runs in a sub-folder (a local XAMPP install such as /surehelpsolution.com/public),
 * that misses the app entirely and every button, filter and form in the portals fails. Here the
 * folder is added to that address. At a domain root (production) the base path is empty and the
 * response is never touched.
 */
class SubfolderLivewire
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $base = $request->getBasePath();

        if ($base === '' || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }
        $content = (string) $response->getContent();
        if (str_contains($content, 'data-update-uri="/livewire/')) {
            $response->setContent(str_replace('data-update-uri="/livewire/', 'data-update-uri="'.$base.'/livewire/', $content));
        }

        return $response;
    }
}
