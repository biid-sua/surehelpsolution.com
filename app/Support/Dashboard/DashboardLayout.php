<?php

namespace App\Support\Dashboard;

use App\Models\Organization;
use App\Models\User;

/**
 * The sections of a business user's dashboard and the order they come in (D77). Everyone starts with
 * the calendar on top; each person can hide, show and reorder sections, and that choice is theirs alone.
 * Sections they aren't allowed to see never appear, whatever is saved.
 */
class DashboardLayout
{
    /**
     * In default order. `size`: full width, or wide / narrow, which share a row when they sit next to each other.
     *
     * @var array<string, array{label: string, description: string, icon: string, permission: ?string, size: string, period: bool}>
     */
    public const SECTIONS = [
        'calendar' => ['label' => 'Calendar', 'description' => 'Your schedule: bookings, visits and busy times', 'icon' => 'calendar', 'permission' => 'calls.view', 'size' => 'full', 'period' => false],
        'numbers' => ['label' => 'Key numbers', 'description' => 'Calls, appointments, leads and follow-ups', 'icon' => 'chart', 'permission' => null, 'size' => 'full', 'period' => true],
        'call_volume' => ['label' => 'Call volume', 'description' => 'Calls per hour or per day', 'icon' => 'phone', 'permission' => null, 'size' => 'wide', 'period' => true],
        'schedule' => ['label' => "Today's schedule", 'description' => 'Appointments and visits booked for today', 'icon' => 'list', 'permission' => null, 'size' => 'narrow', 'period' => false],
        'recent_calls' => ['label' => 'Recent calls', 'description' => 'The latest calls our team handled', 'icon' => 'phone', 'permission' => null, 'size' => 'full', 'period' => false],
        'activity' => ['label' => 'Customer activity', 'description' => 'New and returning customers, recent interactions', 'icon' => 'users', 'permission' => 'customers.view', 'size' => 'full', 'period' => true],
    ];

    /**
     * The sections this person may see, in their order, each marked visible or hidden.
     *
     * @return list<array{key: string, visible: bool, label: string, description: string, icon: string, size: string, period: bool}>
     */
    public function for(User $user, Organization $organization): array
    {
        $saved = collect(is_array($user->dashboard_layout) ? $user->dashboard_layout : [])
            ->filter(fn ($row) => is_array($row) && isset(self::SECTIONS[$row['key'] ?? '']))
            ->unique('key')
            ->mapWithKeys(fn (array $row) => [$row['key'] => (bool) ($row['visible'] ?? true)]);
        // Sections added after someone saved their layout appear at the end, shown.
        $order = $saved->keys()->merge(array_diff(array_keys(self::SECTIONS), $saved->keys()->all()));

        return $order
            ->filter(fn (string $key) => $this->allowed($user, $organization, $key))
            ->map(fn (string $key) => ['key' => $key, 'visible' => $saved->get($key, true)] + self::SECTIONS[$key])
            ->values()
            ->all();
    }

    /** Show or hide one section. */
    public function toggle(User $user, Organization $organization, string $key): void
    {
        $this->save($user, array_map(
            fn (array $row) => $row['key'] === $key ? ['key' => $key, 'visible' => ! $row['visible']] : $row,
            $this->stored($user, $organization),
        ));
    }

    /** Move one section up (-1) or down (+1) among the ones this person can see. */
    public function move(User $user, Organization $organization, string $key, int $direction): void
    {
        $rows = $this->stored($user, $organization);
        $visibleKeys = array_column($this->for($user, $organization), 'key');
        $index = array_search($key, $visibleKeys, true);
        $target = $index === false ? null : ($visibleKeys[$index + ($direction < 0 ? -1 : 1)] ?? null);
        if ($target === null) {
            return;
        }
        $keys = array_column($rows, 'key');
        [$a, $b] = [array_search($key, $keys, true), array_search($target, $keys, true)];
        [$rows[$a], $rows[$b]] = [$rows[$b], $rows[$a]];
        $this->save($user, $rows);
    }

    public function reset(User $user): void
    {
        $user->forceFill(['dashboard_layout' => null])->save();
    }

    /**
     * The visible sections with their width on a three-column grid: a wide and a narrow section next to
     * each other share a row (2 + 1); everything else takes the full row. One flat list, so each section
     * keeps its place in the page when the order changes (its chart or calendar isn't rebuilt).
     *
     * @param  list<array{key: string, visible: bool, size: string}>  $sections
     * @return list<array{key: string, size: string, span: int}>
     */
    public static function place(array $sections): array
    {
        $visible = array_values(array_filter($sections, fn (array $s) => $s['visible']));
        $placed = [];
        for ($i = 0; $i < count($visible); $i++) {
            $next = $visible[$i + 1] ?? null;
            if ($next && in_array($visible[$i]['size'].'+'.$next['size'], ['wide+narrow', 'narrow+wide'], true)) {
                $placed[] = $visible[$i] + ['span' => $visible[$i]['size'] === 'wide' ? 2 : 1];
                $placed[] = $next + ['span' => $next['size'] === 'wide' ? 2 : 1];
                $i++;

                continue;
            }
            $placed[] = $visible[$i] + ['span' => 3];
        }

        return $placed;
    }

    /**
     * Every known section in saved order (including ones this person can't see now, so a change of
     * permissions doesn't lose their choice).
     *
     * @return list<array{key: string, visible: bool}>
     */
    private function stored(User $user, Organization $organization): array
    {
        $allowed = collect($this->for($user, $organization))->keyBy('key');
        $saved = collect(is_array($user->dashboard_layout) ? $user->dashboard_layout : [])
            ->filter(fn ($row) => is_array($row) && isset(self::SECTIONS[$row['key'] ?? '']))
            ->unique('key')->keyBy('key');
        $order = $saved->keys()->merge(array_diff(array_keys(self::SECTIONS), $saved->keys()->all()));

        return $order->map(fn (string $key) => [
            'key' => $key,
            'visible' => (bool) ($allowed->get($key)['visible'] ?? $saved->get($key)['visible'] ?? true),
        ])->values()->all();
    }

    /** @param list<array{key: string, visible: bool}> $rows */
    private function save(User $user, array $rows): void
    {
        $user->forceFill(['dashboard_layout' => array_values($rows)])->save();
    }

    private function allowed(User $user, Organization $organization, string $key): bool
    {
        $permission = self::SECTIONS[$key]['permission'];

        return $permission === null || $user->can($permission, $organization);
    }
}
