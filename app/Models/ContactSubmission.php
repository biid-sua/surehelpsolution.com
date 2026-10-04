<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactSubmission extends Model
{
    protected $casts = [
        'sms_consent' => 'boolean',
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
}
