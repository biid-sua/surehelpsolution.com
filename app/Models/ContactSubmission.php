<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A website enquiry: from the contact form, or a lead the sales chat captured (D78), with what the
 * chat learned, a hot / warm / cold score and a booked demo, followed up to its outcome (D96).
 *
 * @property array<string, string>|null $details
 */
class ContactSubmission extends Model
{
    public const INQUIRY_TYPES = [
        'general' => 'General inquiry',
        'sales' => 'Sales',
        'demo' => 'Demo request',
        'support' => 'Support',
        'enterprise' => 'Enterprise',
    ];

    /** Where it came from: the contact form, or the sales chat (D78). */
    public const SOURCES = ['form' => 'Contact form', 'chat' => 'Chat'];

    protected $casts = [
        'sms_consent' => 'boolean',
        'handled_at' => 'datetime',
        'details' => 'array',
        'demo_at' => 'datetime',
        'demo_booked_at' => 'datetime',
        'demo_reminded_day_at' => 'datetime',
        'demo_reminded_hour_at' => 'datetime',
        'demo_followed_up_at' => 'datetime',
        'demo_nudged_at' => 'datetime',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'company',
        'inquiry_type',
        'message',
        'sms_consent',
        'ip_address',
        'source',
        'details',
        'score',
        'demo_at',
        'demo_status',
    ];

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Who runs the demo call (D96).
     *
     * @return BelongsTo<User, $this>
     */
    public function demoOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demo_owner_user_id');
    }

    /**
     * The business they signed up after the demo, matched by email (D96).
     *
     * @return BelongsTo<Organization, $this>
     */
    public function signedUpOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'signed_up_organization_id');
    }

    /** A demo that has happened (or should have) and still needs marking. */
    public function demoNeedsOutcome(): bool
    {
        return $this->demo_status === 'booked' && $this->demo_at !== null && $this->demo_at->isPast();
    }

    /** @return HasOne<SalesChat, $this> */
    public function chat(): HasOne
    {
        return $this->hasOne(SalesChat::class);
    }

    public function inquiryLabel(): string
    {
        return self::INQUIRY_TYPES[$this->inquiry_type] ?? ucfirst((string) $this->inquiry_type);
    }
}
