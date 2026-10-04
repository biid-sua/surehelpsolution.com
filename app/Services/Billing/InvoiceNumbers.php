<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free yearly invoice numbers (INV-2026-0001), reserved under a row lock so two invoices
 * issued at the same moment never share a number (same pattern as call ids, FIX-04).
 */
class InvoiceNumbers
{
    public function next(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        $number = DB::transaction(function () use ($year) {
            DB::table('invoice_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
            $current = (int) DB::table('invoice_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
            DB::table('invoice_sequences')->where('year', $year)->update(['last_number' => $current + 1]);

            return $current + 1;
        });

        return sprintf('INV-%d-%04d', $year, $number);
    }
}
