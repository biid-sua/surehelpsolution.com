<?php

namespace App\Models;

use App\Enums\InboxChannel;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A business's AI assistant settings (spec §26A): on/off, per-channel mode, name, tone, what it may do.
 *
 * @property array<string, string>|null $modes
 */
class AiAssistant extends Model
{
    use BelongsToOrganization;

    public const MODES = ['off' => 'Off', 'suggest' => 'Suggest replies', 'auto' => 'Reply automatically'];

    public const TONES = ['friendly' => 'Friendly', 'professional' => 'Professional', 'concise' => 'Short and to the point'];

    protected $fillable = [
        'organization_id', 'is_enabled', 'name', 'tone', 'modes', 'can_book', 'bookings_need_confirmation', 'handover_message', 'instructions',
    ];

    protected $attributes = ['name' => 'Assistant', 'tone' => 'friendly', 'can_book' => true, 'bookings_need_confirmation' => false, 'is_enabled' => false];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'modes' => 'array',
            'can_book' => 'boolean',
            'bookings_need_confirmation' => 'boolean',
        ];
    }

    public static function for(Organization $organization): self
    {
        return self::query()->forOrganization($organization)->first() ?? new self(['organization_id' => $organization->id]);
    }

    public function modeFor(InboxChannel $channel): string
    {
        if (! $this->is_enabled) {
            return 'off';
        }
        $mode = (string) ($this->modes[$channel->value] ?? 'off');

        return array_key_exists($mode, self::MODES) ? $mode : 'off';
    }

    public function handoverMessage(): string
    {
        return filled($this->handover_message)
            ? (string) $this->handover_message
            : 'I want to make sure you get the right answer, so I\'ve passed this to our team. Someone will reply here as soon as possible.';
    }
}
