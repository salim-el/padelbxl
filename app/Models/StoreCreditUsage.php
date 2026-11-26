<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Part d'un paiement réglée avec un avoir. */
class StoreCreditUsage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['store_credit_id', 'payment_id', 'amount_cents'];
}
