<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** F30 : contestation bancaire d'un paiement, signalée par Stripe. */
class Chargeback extends Model
{
    public $timestamps = false;

    protected $fillable = ['payment_id', 'stripe_dispute_id', 'status', 'opened_at', 'closed_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
