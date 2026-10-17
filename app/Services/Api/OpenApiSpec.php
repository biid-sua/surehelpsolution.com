<?php

namespace App\Services\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * An OpenAPI 3.1 description of `/api/v1` built from the routes themselves (API-19), so it can't drift
 * from what the server does: paths, methods, path parameters, sign-in, idempotency keys, rate limits
 * and each controller method's summary. Field-level detail stays in docs/api.md.
 */
class OpenApiSpec
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        $paths = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') && $uri !== 'api/v1') {
                continue;
            }
            $path = '/'.ltrim(Str::after($uri, 'api/v1'), '/');
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, $path, $method);
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'SureHelp API',
                'version' => 'v1',
                'description' => 'Every response uses the envelope {success, message, data, meta?}; errors add errors{field: [..]}. Fields and rules: docs/api.md.',
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/v1']],
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Token from POST /login (30 days; POST /refresh-token renews it).']],
                'schemas' => [
                    'Envelope' => ['type' => 'object', 'required' => ['success'], 'properties' => [
                        'success' => ['type' => 'boolean'], 'message' => ['type' => 'string'],
                        'data' => ['type' => ['object', 'null']], 'meta' => ['type' => ['object', 'null']],
                        'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ]],
                ],
                'parameters' => [
                    'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 100],
                        'description' => 'A new UUID per item created; the same key when retrying (24 hours).'],
                ],
            ],
            'paths' => $paths,
        ];
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $path, string $method): array
    {
        $middleware = $route->gatherMiddleware();
        $signedIn = in_array('auth:sanctum', $middleware, true);
        $parameters = [];
        foreach ($route->parameterNames() as $name) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        }
        if (in_array('idempotent', $middleware, true)) {
            $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
        }
        $notes = array_values(array_filter(array_map(fn ($m) => match (true) {
            is_string($m) && str_starts_with($m, 'can:') => 'Permission: '.substr($m, 4),
            is_string($m) && str_starts_with($m, 'role:') => 'Role: '.str_replace(',', ' or ', substr($m, 5)),
            is_string($m) && str_starts_with($m, 'feature:') => 'Needs the '.substr($m, 8).' feature',
            is_string($m) && str_starts_with($m, 'throttle:') && substr_count($m, ',') === 1 => 'Rate limit: '.str_replace(',', ' per ', substr($m, 9)).' min',
            $m === 'signed' => 'Signed link: open the URL exactly as the API gave it',
            default => null,
        }, $middleware)));
        $segments = explode('/', trim($path, '/'));

        return array_filter([
            'operationId' => Str::camel(strtolower($method).' '.str_replace(['{', '}', '/', '-'], ['', '', ' ', ' '], $path)),
            'tags' => [in_array($segments[0], ['client', 'agent', 'admin'], true) ? $segments[0].'/'.($segments[1] ?? '') : $segments[0]],
            'summary' => $this->summary($route),
            'description' => $notes ? implode('. ', $notes).'.' : null,
            'security' => $signedIn ? [['bearer' => []]] : [],
            'parameters' => $parameters ?: null,
            'responses' => [
                '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]],
                'default' => ['description' => 'Error envelope (401, 403, 404, 409, 422, 429)', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]],
            ],
        ], fn ($v) => $v !== null);
    }

    /** The first sentence of the controller method's doc comment, or its name in words. */
    private function summary(Route $route): string
    {
        $action = $route->getActionName();
        [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
        if (class_exists($class) && method_exists($class, $method)) {
            $doc = (string) (new ReflectionMethod($class, $method))->getDocComment();
            if ($doc === '' && $method === '__invoke') {
                $doc = (string) (new \ReflectionClass($class))->getDocComment();   // single-action controllers describe themselves
            }
            $text = trim((string) preg_replace('/^\s*\/?\*+\/?\s?/m', '', $doc));
            $text = trim((string) preg_replace('/@\w+.*$/s', '', $text));
            if ($text !== '') {
                return Str::limit((string) preg_replace('/\s+/', ' ', Str::before($text.' ', '. ').'.'), 160, '');
            }
        }

        return Str::ucfirst(Str::snake($method === '__invoke' ? class_basename($class) : $method, ' '));
    }
}
