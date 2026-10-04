<?php

namespace App\Services\Billing;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Schema;

/**
 * How SureHelp gets paid and what goes on invoices, edited in Admin › Billing › Settings.
 */
class BillingSettings
{
    public const KEYS = [
        'company_name' => 'SureHelp Solution',
        'company_address' => '',
        'company_email' => '',
        'tax_id' => '',
        'payoneer_email' => '',          // the Payoneer account clients pay
        'payoneer_payment_link' => '',   // a reusable Payoneer payment link, used when an invoice has none of its own
        'bank_details' => '',            // Payoneer receiving account details (US ACH / wire, EUR, GBP …)
        'payment_instructions' => 'Please include the invoice number with your payment.',
        'due_days' => 7,
    ];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->values === null) {
            $saved = Schema::hasTable('platform_settings')
                ? PlatformSetting::query()->whereIn('key', array_map(fn ($k) => 'billing.'.$k, array_keys(self::KEYS)))->pluck('value', 'key')->all()
                : [];

            $this->values = [];
            foreach (self::KEYS as $key => $default) {
                $this->values[$key] = $saved['billing.'.$key] ?? $default;
            }
        }

        return $this->values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach (array_intersect_key($values, self::KEYS) as $key => $value) {
            PlatformSetting::put('billing.'.$key, $value);
        }
        $this->values = null;
    }

    public function dueDays(): int
    {
        return max(0, (int) $this->get('due_days'));
    }
}
