<?php

namespace App\Services\Social\Contracts;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialAuthorizationLost;
use App\Exceptions\SocialPostRejected;
use App\Models\SocialAccount;
use App\Services\Social\Data\PublishRequest;
use App\Services\Social\Data\PublishResult;

/**
 * Publishes to one network. Any other exception is treated as temporary and retried.
 */
interface SocialPublisher
{
    public function network(): SocialNetwork;

    /**
     * @throws SocialAuthorizationLost when the account must be reconnected
     * @throws SocialPostRejected when the network refused the content
     */
    public function publish(SocialAccount $account, string $accessToken, PublishRequest $request): PublishResult;
}
