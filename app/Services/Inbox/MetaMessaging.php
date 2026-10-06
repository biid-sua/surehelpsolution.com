<?php

namespace App\Services\Inbox;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialAuthorizationLost;
use App\Exceptions\SocialPostRejected;
use App\Models\SocialAccount;
use App\Services\Social\Connectors\MetaGraph;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Turns Messenger / Instagram direct messages on for a connected account (D37): subscribes our app
 * to the Page's message webhooks. Instagram accounts are reached through their linked Page.
 */
class MetaMessaging
{
    public function __construct(
        private readonly MetaGraph $graph,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws ValidationException when Meta refuses (usually: reconnect to grant the messaging permissions)
     */
    public function enable(SocialAccount $account): void
    {
        if (! in_array($account->network, [SocialNetwork::Facebook, SocialNetwork::Instagram], true)) {
            throw ValidationException::withMessages(['channel' => ['Only Facebook Pages and Instagram accounts can receive messages here.']]);
        }

        $pageId = $account->network === SocialNetwork::Facebook ? $account->external_id : (string) ($account->meta['page_id'] ?? '');
        $page = $account->network === SocialNetwork::Facebook ? $account : SocialAccount::withoutGlobalScopes()
            ->where('organization_id', $account->organization_id)->where('network', SocialNetwork::Facebook->value)->where('external_id', $pageId)->first();
        $token = $page->access_token ?? $account->access_token;

        try {
            $this->graph->post($pageId.'/subscribed_apps', $token, ['subscribed_fields' => 'messages,message_echoes']);
        } catch (SocialAuthorizationLost|SocialPostRejected $e) {
            throw ValidationException::withMessages(['channel' => ['Meta didn\'t allow messages for '.$account->displayName().'. Reconnect Facebook & Instagram and allow message access, then try again.']]);
        }

        $account->forceFill(['messaging_enabled' => true])->save();
        $this->audit->record('inbox.channel_enabled', $account, new: ['network' => $account->network->value], organization: $account->organization, label: $account->displayName());
    }

    public function disable(SocialAccount $account): void
    {
        $account->forceFill(['messaging_enabled' => false])->save();
        $this->audit->record('inbox.channel_disabled', $account, new: ['network' => $account->network->value], organization: $account->organization, label: $account->displayName());
    }
}
