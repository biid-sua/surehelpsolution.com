<?php

namespace App\Services\Admin;

use App\Enums\EscalationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Client health (D86): a 0–100 score per active business, so the team can step in before a client
 * leaves. It starts at 100 and loses points for each warning sign, with the reasons in plain words.
 * Worked out in a few grouped queries for all businesses at once, and cached for 10 minutes.
 */
class ClientHealth
{
    public const HEALTHY = 75;

    public const WATCH = 50;

    /**
     * @return array<int, array{score: int, band: string, reasons: list<string>}> organization id => health
     */
    public function all(): array
    {
        return Cache::remember('client-health', now()->addMinutes(10), fn () => $this->compute());
    }

    /** @return array{score: int, band: string, reasons: list<string>}|null */
    public function for(Organization $organization): ?array
    {
        return $this->all()[$organization->id] ?? null;
    }

    public function forget(): void
    {
        Cache::forget('client-health');
    }

    public static function label(string $band): string
    {
        return ['healthy' => 'Healthy', 'watch' => 'Watch', 'risk' => 'At risk'][$band] ?? $band;
    }

    public static function tone(string $band): string
    {
        return ['healthy' => 'success', 'watch' => 'warning', 'risk' => 'danger'][$band] ?? 'neutral';
    }

    /** @return array<int, array{score: int, band: string, reasons: list<string>}> */
    private function compute(): array
    {
        $organizations = Organization::query()->where('status', OrganizationStatus::Active)->whereNotNull('setup_completed_at')->get(['id', 'created_at']);
        if ($organizations->isEmpty()) {
            return [];
        }
        $ids = $organizations->pluck('id');
        $now = now();

        $recent = $this->countBy('call_logs', $ids, fn ($q) => $q->where('created_at', '>=', $now->copy()->subDays(30)));
        $before = $this->countBy('call_logs', $ids, fn ($q) => $q->whereBetween('created_at', [$now->copy()->subDays(60), $now->copy()->subDays(30)]));
        $overdue = DB::table('invoices')->whereIn('organization_id', $ids)->where('status', InvoiceStatus::Open->value)->where('due_at', '<', $now)
            ->groupBy('organization_id')->selectRaw('organization_id, COUNT(*) as n, MIN(due_at) as oldest')->get()->keyBy('organization_id');
        $logins = DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')
            ->whereIn('organization_user.organization_id', $ids)->where('organization_user.status', 'active')
            ->groupBy('organization_user.organization_id')->selectRaw('organization_user.organization_id as id, MAX(users.last_login_at) as last')->pluck('last', 'id');
        $escalations = $this->countBy('escalations', $ids, fn ($q) => $q->where('status', '!=', EscalationStatus::Resolved->value)->where('created_at', '<', $now->copy()->subDays(2)));
        $ratings = DB::table('surveys')->whereIn('organization_id', $ids)->whereNotNull('score')->where('answered_at', '>=', $now->copy()->subDays(30))
            ->groupBy('organization_id')->selectRaw('organization_id, AVG(score) as avg, COUNT(*) as n')->get()->keyBy('organization_id');

        $health = [];
        foreach ($organizations as $organization) {
            $id = $organization->id;
            $score = 100;
            $reasons = [];

            $now30 = (int) ($recent[$id] ?? 0);
            $prev30 = (int) ($before[$id] ?? 0);
            if ($organization->created_at->lt($now->copy()->subDays(60)) && $prev30 >= 10) {
                $drop = (int) round(100 * ($prev30 - $now30) / $prev30);
                if ($now30 === 0) {
                    $score -= 35;
                    $reasons[] = 'No calls in 30 days (had '.$prev30.' the month before)';
                } elseif ($drop >= 50) {
                    $score -= 25;
                    $reasons[] = 'Calls down '.$drop.'% on the month before';
                } elseif ($drop >= 25) {
                    $score -= 10;
                    $reasons[] = 'Calls down '.$drop.'% on the month before';
                }
            }

            if ($row = $overdue->get($id)) {
                $days = (int) floor($now->diffInDays(Carbon::parse($row->oldest), true));
                $score -= $days > 14 ? 30 : 15;
                $reasons[] = $row->n.' overdue '.($row->n === 1 ? 'invoice' : 'invoices').' ('.$days.' days late)';
            }

            $last = $logins[$id] ?? null;
            $since = $last ? (int) floor($now->diffInDays(Carbon::parse($last), true)) : null;
            if ($organization->created_at->lt($now->copy()->subDays(14))) {
                if ($since === null || $since > 30) {
                    $score -= 20;
                    $reasons[] = $since === null ? 'Nobody has signed in yet' : 'Nobody has signed in for '.$since.' days';
                } elseif ($since > 14) {
                    $score -= 10;
                    $reasons[] = 'Last sign-in '.$since.' days ago';
                }
            }

            if (($open = (int) ($escalations[$id] ?? 0)) >= 3) {
                $score -= 10;
                $reasons[] = $open.' escalations open for more than 2 days';
            }

            if (($r = $ratings->get($id)) && (int) $r->n >= 3 && (float) $r->avg < 3.5) {
                $score -= 10;
                $reasons[] = 'Customers rate them '.number_format((float) $r->avg, 1).' / 5 lately';
            }

            $score = max(0, $score);
            $health[$id] = ['score' => $score, 'band' => $score >= self::HEALTHY ? 'healthy' : ($score >= self::WATCH ? 'watch' : 'risk'), 'reasons' => $reasons];
        }

        return $health;
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<int, int>
     */
    private function countBy(string $table, $ids, callable $where): array
    {
        $query = DB::table($table)->whereIn('organization_id', $ids);
        $where($query);

        return $query->groupBy('organization_id')->selectRaw('organization_id, COUNT(*) as n')->pluck('n', 'organization_id')->map(fn ($n) => (int) $n)->all();
    }
}
