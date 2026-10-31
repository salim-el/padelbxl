<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BookingPlayer extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_id', 'user_id', 'guest_email', 'is_organizer', 'share_cents', 'status'];

    protected function casts(): array
    {
        return ['is_organizer' => 'boolean'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function accessPass(): HasOne
    {
        return $this->hasOne(AccessPass::class);
    }
}
