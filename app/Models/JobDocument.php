<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An estimate or an invoice a business sends its customer (D115). Statuses change only through
 * App\Services\Documents\JobDocuments (docs/state-machines.md).
 *
 * @property string $ulid
 * @property string $type
 * @property string $status
 * @property list<array{description: string, quantity: float|int, unit_cents: int}> $items
 * @property list<string>|null $sent_via
 * @property Carbon|null $valid_until
 * @property Carbon|null $due_on
 * @property Carbon|null $sent_at
 * @property Carbon|null $viewed_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 * @property Carbon|null $paid_at
 */
class JobDocument extends Model
{
    use BelongsToOrganization;

    public const ESTIMATE = 'estimate';

    public const INVOICE = 'invoice';

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const INVOICED = 'invoiced';

    public const PAID = 'paid';

    public const VOID = 'void';

    public const STATUS_LABELS = [
        self::DRAFT => 'Draft', self::SENT => 'Sent', self::ACCEPTED => 'Accepted', self::DECLINED => 'Declined',
        self::INVOICED => 'Invoiced', self::PAID => 'Paid', self::VOID => 'Void',
    ];

    public const STATUS_TONES = [
        self::DRAFT => 'neutral', self::SENT => 'info', self::ACCEPTED => 'success', self::DECLINED => 'danger',
        self::INVOICED => 'success', self::PAID => 'success', self::VOID => 'neutral',
    ];

    protected $fillable = [
        'organization_id', 'customer_id', 'appointment_id', 'estimate_id', 'payment_request_id', 'created_by_user_id', 'type', 'number', 'status', 'title',
        'items', 'notes', 'subtotal_cents', 'tax_rate_bp', 'tax_cents', 'total_cents', 'token', 'valid_until', 'due_on', 'sent_via', 'sent_at', 'viewed_at',
        'accepted_at', 'accepted_name', 'declined_at', 'declined_reason', 'paid_at', 'paid_how',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'sent_via' => 'array', 'subtotal_cents' => 'integer', 'tax_rate_bp' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer',
            'valid_until' => 'date', 'due_on' => 'date', 'sent_at' => 'datetime', 'viewed_at' => 'datetime', 'accepted_at' => 'datetime',
            'declined_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (JobDocument $document) {
            $document->ulid ??= (string) Str::ulid();
            $document->token ??= Str::random(40);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<JobDocument, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(JobDocument::class, 'estimate_id');
    }

    /** @return BelongsTo<PaymentRequest, $this> */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function isEstimate(): bool
    {
        return $this->type === self::ESTIMATE;
    }

    public function label(): string
    {
        return ($this->isEstimate() ? 'Estimate ' : 'Invoice ').$this->number;
    }

    public function total(): string
    {
        return Money::format($this->total_cents);
    }

    public function link(): string
    {
        return route('documents.show', $this->token);
    }

    /** Can the customer still act on it (accept an estimate, pay an invoice)? */
    public function isOpen(): bool
    {
        return $this->status === self::SENT && (! $this->isEstimate() || ! $this->valid_until || ! $this->valid_until->isPast() || $this->valid_until->isToday());
    }
}
