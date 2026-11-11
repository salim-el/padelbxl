<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    public $timestamps = false;

    protected $fillable = ['number', 'booking_id', 'payment_id', 'issued_at', 'customer_name', 'customer_company', 'customer_vat', 'net_cents', 'vat_rate', 'vat_cents', 'total_cents'];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'vat_rate' => 'decimal:2'];
    }

    // Le remboursement est toujours total : une facture a au plus une note de crédit.
    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class);
    }
}
