<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A website enquiry: from the contact form, or a lead the sales chat captured (D78), with what the
 * chat learned, a hot / warm / cold score and a booked demo.
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
