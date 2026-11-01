<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * How a business answers new leads (D109): a text and an email straight away, and optionally a call
 * that rings the owner and connects them. One per business; without a row, nothing is sent.
 *
 * @property bool $is_enabled
 * @property bool $text_lead
 * @property string|null $text_message
 * @property bool $email_lead
 * @property string|null $email_message
 * @property bool $connect_call
 * @property bool $call_in_hours_only
 * @property string|null $webhook_token
 */
class LeadSettings extends Model
{
    use BelongsToOrganization;

    protected $table = 'lead_settings';

    public const DEFAULT_TEXT = 'Hi {first_name}, thanks for contacting {business}! We got your request and will call you shortly. Reply here with any details. Reply STOP to opt out.';

    public const DEFAULT_EMAIL = "Hi {first_name},\n\nThanks for contacting {business}. We got your request and someone will call you shortly.\n\nIf it's easier, just reply to this email with any details or a good time to reach you.";

    protected $fillable = ['organization_id', 'is_enabled', 'text_lead', 'text_message', 'email_lead', 'email_message', 'connect_call', 'call_in_hours_only', 'webhook_token'];

    protected $attributes = ['is_enabled' => false, 'text_lead' => true, 'email_lead' => true, 'connect_call' => false, 'call_in_hours_only' => true];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'text_lead' => 'boolean', 'email_lead' => 'boolean', 'connect_call' => 'boolean', 'call_in_hours_only' => 'boolean'];
    }

    public static function for(Organization $organization): self
    {
        return self::query()->forOrganization($organization)->first() ?? new self(['organization_id' => $organization->id]);
    }

    /** The secret in the business's lead link; made on first use. */
    public function token(): string
    {
        if (! $this->webhook_token) {
            $this->forceFill(['webhook_token' => Str::random(40)])->save();
        }

        return (string) $this->webhook_token;
    }

    public function textFor(string $firstName, string $business): string
    {
        return self::withValues((string) ($this->text_message ?: self::DEFAULT_TEXT), $firstName, $business);
    }

    public function emailFor(string $firstName, string $business): string
    {
        return self::withValues((string) ($this->email_message ?: self::DEFAULT_EMAIL), $firstName, $business);
    }

    private static function withValues(string $template, string $firstName, string $business): string
    {
        return trim(str_replace(['{first_name}', '{business}'], [$firstName !== '' ? $firstName : 'there', $business], $template));
    }
}
