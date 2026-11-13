<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    public $timestamps = false;

    protected $fillable = ['number', 'invoice_id', 'issued_at', 'net_cents', 'vat_cents', 'total_cents', 'reason'];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime'];
    }
}
