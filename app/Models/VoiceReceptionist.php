<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A business's AI receptionist settings (D108): when it answers calls, its greeting and voice, and
 * what it may do. One per business; a business without a row has it off.
 *
 * @property string $mode
 * @property string|null $greeting
 * @property string $voice
 * @property bool $can_book
 * @property bool $transfer_to_owner
 * @property int $max_minutes
 */
class VoiceReceptionist extends Model
{
    use BelongsToOrganization;

    public const MODES = [
        'off' => 'Off',
        'backup' => 'When nobody can answer, day or night',
        'after_hours' => 'Only when you\'re closed',
        'always' => 'Answer every call first',
    ];

    /** Amazon Polly voices through Twilio: natural-sounding US English. */
    public const VOICES = [
        'Polly.Joanna-Neural' => 'Joanna (female)',
        'Polly.Ruth-Neural' => 'Ruth (female)',
        'Polly.Matthew-Neural' => 'Matthew (male)',
        'Polly.Stephen-Neural' => 'Stephen (male)',
    ];

    protected $fillable = ['organization_id', 'mode', 'greeting', 'voice', 'can_book', 'transfer_to_owner', 'max_minutes'];

    protected $attributes = ['mode' => 'off', 'voice' => 'Polly.Joanna-Neural', 'can_book' => true, 'transfer_to_owner' => true, 'max_minutes' => 8];

    protected function casts(): array
    {
        return ['can_book' => 'boolean', 'transfer_to_owner' => 'boolean', 'max_minutes' => 'integer'];
    }

    public static function for(Organization $organization): self
    {
        return self::query()->forOrganization($organization)->first() ?? new self(['organization_id' => $organization->id]);
    }

    public function greetingFor(string $business): string
    {
        return filled($this->greeting)
            ? str_replace('{business}', $business, (string) $this->greeting)
            : "Thanks for calling {$business}. I'm the virtual assistant. How can I help you today?";
    }

    public function voiceName(): string
    {
        return array_key_exists($this->voice, self::VOICES) ? $this->voice : 'Polly.Joanna-Neural';
    }
}
