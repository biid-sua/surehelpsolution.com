<?php

namespace App\Services\Account;

use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which legal documents a person must accept (config account.legal) and whether they have
 * accepted the current version of each (spec CMP-06). Bumping a version asks everyone again.
 */
class LegalDocuments
{
    /** @return array<string, array{label: string, version: string, route: string, roles: list<string>}> */
    public function requiredFor(User $user): array
    {
        return array_filter(config('account.legal', []), fn (array $doc) => in_array($user->role, $doc['roles'], true));
    }

    /** @return array<string, array{label: string, version: string, route: string, roles: list<string>}> */
    public function pendingFor(User $user): array
    {
        $required = $this->requiredFor($user);
        if ($required === []) {
            return [];
        }

        $accepted = LegalAcceptance::query()->where('user_id', $user->id)->whereIn('document', array_keys($required))
            ->get(['document', 'version'])->map(fn (LegalAcceptance $a) => $a->document.'@'.$a->version)->all();

        return array_filter($required, fn (array $doc, string $key) => ! in_array($key.'@'.$doc['version'], $accepted, true), ARRAY_FILTER_USE_BOTH);
    }

    /** Has this person ever accepted any version (so a new version is an update, not a first time)? */
    public function hasAcceptedBefore(User $user): bool
    {
        return LegalAcceptance::query()->where('user_id', $user->id)->exists();
    }

    /** Record acceptance of the current versions of everything this person must accept. */
    public function accept(User $user, ?Request $request = null): void
    {
        foreach ($this->requiredFor($user) as $key => $doc) {
            LegalAcceptance::query()->firstOrCreate(
                ['user_id' => $user->id, 'document' => $key, 'version' => $doc['version']],
                ['accepted_at' => now(), 'ip_address' => $request?->ip(), 'user_agent' => mb_substr((string) $request?->userAgent(), 0, 255) ?: null],
            );
        }
    }

    /** A fingerprint of the versions that apply, to remember "all accepted" for a session. */
    public function fingerprint(User $user): string
    {
        return sha1($user->id.'|'.collect($this->requiredFor($user))->map(fn (array $d, string $k) => $k.'@'.$d['version'])->join(','));
    }
}
