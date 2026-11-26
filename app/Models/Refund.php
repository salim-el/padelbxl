<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    public $timestamps = false;

    protected $fillable = ['payment_id', 'amount_cents', 'reason', 'method', 'status', 'credit_note_id', 'stripe_refund_id'];
}
