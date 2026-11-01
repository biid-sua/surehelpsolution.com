<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Where a conversation happens (spec §26, D37). Each channel decides when a reply may still be sent.
 */
enum InboxChannel: string
{
    case WebChat = 'web_chat';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Sms = 'sms';
    // A call the AI receptionist answered (D108): its transcript; replies can't be sent here.
    case Phone = 'phone';

    /** Channels a reply can be written on (and the messaging assistant works on). @return list<self> */
    public static function messaging(): array
    {
        return [self::WebChat, self::Facebook, self::Instagram, self::Sms];
    }

    public function label(): string
    {
        return match ($this) {
            self::WebChat => 'Website chat',
            self::Facebook => 'Messenger',
            self::Instagram => 'Instagram',
            self::Sms => 'Text message',
            self::Phone => 'Phone call (AI receptionist)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::WebChat => '#7C3AED',
            self::Facebook => '#0866FF',
            self::Instagram => '#E1306C',
            self::Sms => '#059669',
            self::Phone => '#0284C7',
        };
    }

    /** Longest single message the channel accepts; longer replies are split. */
    public function maxLength(): int
    {
        return match ($this) {
            self::WebChat => 4000,
            self::Facebook => 2000,
            self::Instagram => 1000,
            self::Sms => 1600, // carriers join longer texts from up to 10 parts
            self::Phone => 1000,
        };
    }

    public function isMeta(): bool
    {
        return $this === self::Facebook || $this === self::Instagram;
    }

    /**
     * Whether a reply can go out now. Texts: always (opt-outs are checked when sending, D61). Meta: 24 hours after the customer's last message for anyone,
     * up to 7 days for a person using the human-agent tag; never later (D37).
     *
     * @return array{allowed: bool, human_agent_tag: bool, reason: ?string}
     */
    public function replyWindow(?CarbonInterface $lastInbound, bool $byPerson, ?CarbonInterface $now = null): array
    {
        if (! $this->isMeta()) {
            return ['allowed' => true, 'human_agent_tag' => false, 'reason' => null];
        }

        $now ??= now();
        if ($lastInbound === null) {
            return ['allowed' => false, 'human_agent_tag' => false, 'reason' => $this->label().' only lets businesses reply after the customer writes.'];
        }
        if ($lastInbound->greaterThanOrEqualTo($now->copy()->subDay())) {
            return ['allowed' => true, 'human_agent_tag' => false, 'reason' => null];
        }
        if ($byPerson && $lastInbound->greaterThanOrEqualTo($now->copy()->subDays(7))) {
            return ['allowed' => true, 'human_agent_tag' => true, 'reason' => null];
        }

        return ['allowed' => false, 'human_agent_tag' => false, 'reason' => $byPerson
            ? $this->label().' only allows replies up to 7 days after the customer\'s last message.'
            : $this->label().' only allows automatic replies within 24 hours of the customer\'s last message.'];
    }
}
