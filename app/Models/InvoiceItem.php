<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['invoice_id', 'description', 'quantity', 'unit_cents', 'amount_cents'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cents' => 'integer', 'amount_cents' => 'integer'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
