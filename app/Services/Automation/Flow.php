<?php

namespace App\Services\Automation;

/**
 * Finding your way around an automation's flow (D81). A flow is a list of steps; a branch step has a
 * "then" and an "otherwise" list. A cursor is the path to a step, such as [2] or [2, "then", 0].
 */
final class Flow
{
    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  list<int|string>  $cursor
     * @return array<string, mixed>|null
     */
    public static function at(array $steps, array $cursor): ?array
    {
        $node = $steps;
        foreach ($cursor as $part) {
            if (! is_array($node) || ! array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return is_array($node) && isset($node['type']) ? $node : null;
    }

    /**
     * The step after this one, climbing out of a branch when its list ends; null at the end of the flow.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  list<int|string>  $cursor
     * @return list<int|string>|null
     */
    public static function next(array $steps, array $cursor): ?array
    {
        while ($cursor !== []) {
            $cursor[count($cursor) - 1]++;
            if (self::at($steps, $cursor)) {
                return $cursor;
            }
            if (count($cursor) < 3) {
                return null;
            }
            $cursor = array_slice($cursor, 0, -2);   // back to the branch step itself
        }

        return null;
    }

    /**
     * Into a branch's "then" or "else" list, or past the branch when that list is empty.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  list<int|string>  $cursor
     * @return list<int|string>|null
     */
    public static function enter(array $steps, array $cursor, string $side): ?array
    {
        $inside = [...$cursor, $side, 0];

        return self::at($steps, $inside) ? $inside : self::next($steps, $cursor);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<int|string>|null
     */
    public static function first(array $steps): ?array
    {
        return self::at($steps, [0]) ? [0] : null;
    }

    /**
     * Every step, depth first, for counting and checks.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return \Generator<int, array{0: array<string, mixed>, 1: int}>
     */
    public static function walk(array $steps, int $depth = 1): \Generator
    {
        foreach ($steps as $step) {
            yield [$step, $depth];
            if (($step['type'] ?? null) === 'branch') {
                yield from self::walk((array) ($step['then'] ?? []), $depth + 1);
                yield from self::walk((array) ($step['else'] ?? []), $depth + 1);
            }
        }
    }

    /** @param array<string, mixed> $step */
    public static function waitLabel(array $step): string
    {
        $m = (int) ($step['minutes'] ?? 0);

        return match (true) {
            $m % 1440 === 0 && $m > 0 => 'Wait '.($m / 1440).' '.str('day')->plural($m / 1440),
            $m % 60 === 0 && $m > 0 => 'Wait '.($m / 60).' '.str('hour')->plural($m / 60),
            default => 'Wait '.$m.' '.str('minute')->plural($m),
        };
    }
}
