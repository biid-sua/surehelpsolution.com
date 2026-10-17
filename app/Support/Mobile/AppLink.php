<?php

namespace App\Support\Mobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Turns a web link from a notification into something the mobile app can route on (API-2): the web
 * route's name and its parameters, e.g. /app/calls/01J… → {route: "app.calls.show", params: {call: "01J…"}}.
 * Route names are stable; paths may change. Links that aren't one of our pages give null.
 */
class AppLink
{
    /** @return array{route: string, params: array<string, string>}|null */
    public static function resolve(?string $url): ?array
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($path) || ($host !== null && $host !== parse_url((string) config('app.url'), PHP_URL_HOST) && $host !== request()->getHost())) {
            return null;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpException) {
            return null;
        }
        if (! $route->getName()) {
            return null;
        }

        $params = [];
        foreach ($route->parameters() as $key => $value) {
            $params[$key] = is_object($value) && method_exists($value, 'getRouteKey') ? (string) $value->getRouteKey() : (string) $value;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach ($query as $key => $value) {
            if (is_scalar($value)) {
                $params[(string) $key] ??= (string) $value;
            }
        }

        return ['route' => $route->getName(), 'params' => $params];
    }
}
