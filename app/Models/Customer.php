<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\Automation\AutomationEngine;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person or company the business serves (spec §12).
 *
 * @property CustomerStatus $status
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $sms_consent_at
 * @property Carbon|null $email_consent_at
 */
class Customer extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    public const SOURCES = ['call', 'manual', 'import', 'chatbot', 'backfill', 'message', 'website'];

    public const CONTACT_METHODS = ['phone' => 'Phone call', 'sms' => 'Text message', 'email' => 'Email'];

    protected $fillable = [
        'organization_id', 'first_name', 'last_name', 'company', 'phone', 'phone_e164', 'email',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'status', 'source', 'preferred_contact',
        'sms_consent', 'sms_consent_at', 'email_consent', 'email_consent_at', 'consent_source', 'notes',
        'last_activity_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'sms_consent' => 'boolean',
            'email_consent' => 'boolean',
            'sms_consent_at' => 'datetime',
            'email_consent_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Customer $customer) {
            $customer->ulid ??= (string) Str::ulid();
        });

        // The matching key always follows the visible phone number.
        static::saving(function (Customer $customer) {
            if ($customer->isDirty('phone')) {
                $customer->phone_e164 = Phone::normalize($customer->phone);
            }
            if ($customer->isDirty('email') && $customer->email !== null) {
                $customer->email = Str::lower(trim($customer->email));
            }
        });

        // Automations (D50): a new customer or lead, but not records copied in by a backfill or import.
        static::created(function (Customer $customer) {
            if (! in_array($customer->source, ['backfill', 'import'], true)) {
                DB::afterCommit(fn () => app(AutomationEngine::class)->fire('customer_created', $customer));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function fullName(): string
    {
        $name = trim($this->first_name.' '.$this->last_name);

        return $name !== '' ? $name : ($this->company ?: (Phone::display($this->phone_e164) ?? $this->phone ?? 'Unknown caller'));
    }

    public function displayPhone(): ?string
    {
        return Phone::display($this->phone_e164) ?? $this->phone;
    }

    public function singleLineAddress(): ?string
    {
        $line = collect([$this->address_line1, $this->address_line2, $this->city, trim($this->state.' '.$this->postal_code)])
            ->filter(fn ($part) => filled(trim((string) $part)))
            ->implode(', ');

        return $line !== '' ? $line : null;
    }

    /**
     * @return HasMany<CustomerTimelineEvent, $this>
     */
    public function timeline(): HasMany
    {
        return $this->hasMany(CustomerTimelineEvent::class)->latest('occurred_at')->latest('id');
    }

    /**
     * @return HasMany<CallLog, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * Search by name, company, email, or phone digits.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = preg_replace('/\D/', '', $term);

        return $query->where(function (Builder $q) use ($like, $digits, $term) {
            $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('company', 'like', $like)
                ->orWhere('email', 'like', $like);

            // "Maria Lopez": first word against first name, the rest against last name (portable, no CONCAT).
            if (preg_match('/^(\S+)\s+(.+)$/', $term, $parts)) {
                $q->orWhere(fn (Builder $name) => $name
                    ->where('first_name', 'like', addcslashes($parts[1], '%_\\').'%')
                    ->where('last_name', 'like', addcslashes($parts[2], '%_\\').'%'));
            }

            if (strlen((string) $digits) >= 3) {
                $q->orWhere('phone_e164', 'like', '%'.$digits.'%');
            }
            if (str_starts_with($term, '+')) {
                $q->orWhere('phone_e164', $term);
            }
        });
    }
}
