<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message sent through the website's contact form.
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

    protected $casts = [
        'sms_consent' => 'boolean',
        'handled_at' => 'datetime',
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
    ];

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function inquiryLabel(): string
    {
        return self::INQUIRY_TYPES[$this->inquiry_type] ?? ucfirst((string) $this->inquiry_type);
    }
}
