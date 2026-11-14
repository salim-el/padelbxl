<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_id', 'booking_player_id', 'payer_id', 'method', 'provider_reference', 'amount_cents', 'status', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'immutable_datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
