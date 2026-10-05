<?php

namespace App\Services\Social;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\SocialNetwork;
use App\Exceptions\SocialAuthorizationLost;
use App\Models\SocialAccount;
use App\Notifications\SocialAccountDisconnected;
use App\Services\Social\Connectors\GoogleBusinessConnector;
use App\Services\Social\Connectors\LinkedInConnector;
use App\Services\Social\Connectors\MetaConnector;
use App\Services\Social\Contracts\SocialConnector;
use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\Publishers\FacebookPublisher;
use App\Services\Social\Publishers\GoogleBusinessPublisher;
use App\Services\Social\Publishers\InstagramPublisher;
use App\Services\Social\Publishers\LinkedInPublisher;
use App\Support\Audit\Audit;
use InvalidArgumentException;

/**
 * Picks connectors and publishers, keeps tokens fresh, and flags lost access once (same pattern as
 * CalendarManager, CAL-06).
 */
class SocialManager
{
    public const CONNECTORS = ['meta' => MetaConnector::class, 'linkedin' => LinkedInConnector::class, 'google' => GoogleBusinessConnector::class];

    public const PUBLISHERS = [
        'facebook' => FacebookPublisher::class,
        'instagram' => InstagramPublisher::class,
        'linkedin' => LinkedInPublisher::class,
        'google_business' => GoogleBusinessPublisher::class,
    ];

    public function __construct(
        private readonly NotifyOrganization $notify,
        private readonly Audit $audit,
    ) {}

    public function connector(string $key): SocialConnector
    {
        $class = self::CONNECTORS[$key] ?? throw new InvalidArgumentException("Unknown social connector [{$key}].");

        return app($class);
    }

    /**
     * @return array<string, SocialConnector>
     */
    public function connectors(): array
    {
        return array_map(fn (string $class) => app($class), self::CONNECTORS);
    }

    public function publisher(SocialNetwork $network): SocialPublisher
    {
        return app(self::PUBLISHERS[$network->value]);
    }

    /**
     * A token the network accepts, refreshed shortly before it expires.
     *
     * @throws SocialAuthorizationLost
     */
    public function accessToken(SocialAccount $account): string
    {
        if ($account->needsReconnect()) {
            throw new SocialAuthorizationLost('This account needs to be reconnected.');
        }

        if (! $account->token_expires_at || $account->token_expires_at->greaterThan(now()->addMinutes(5))) {
            return $account->access_token;
        }

        try {
            $tokens = $this->connector($account->network->connector())->refresh($account);
        } catch (SocialAuthorizationLost $e) {
            $this->lost($account, $e->getMessage());

            throw $e;
        }

        // LinkedIn and Google accounts found in one sign-in share its tokens: refresh them together.
        SocialAccount::withoutGlobalScopes()
            ->where('organization_id', $account->organization_id)
            ->where('network', $account->network->value)
            ->get()
            ->filter(fn (SocialAccount $a) => $a->id === $account->id || $a->refresh_token === $account->refresh_token)
            ->each(fn (SocialAccount $a) => $a->forceFill([
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken ?? $a->refresh_token,
                'token_expires_at' => $tokens->expiresAt,
            ])->save());

        return $tokens->accessToken;
    }

    /**
     * Access was revoked or expired: flag it and tell the business once.
     */
    public function lost(SocialAccount $account, string $reason): void
    {
        if ($account->needsReconnect()) {
            return;
        }

        $account->forceFill(['status' => SocialAccount::STATUS_NEEDS_REAUTH, 'last_error' => mb_substr($reason, 0, 500)])->save();
        $this->audit->record('social.access_lost', $account, new: ['network' => $account->network->value, 'reason' => $reason],
            organization: $account->organization, label: $account->displayName());

        if ($account->organization) {
            $this->notify->handle($account->organization, new SocialAccountDisconnected($account), 'social.manage');
        }
    }
}
