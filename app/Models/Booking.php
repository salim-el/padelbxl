<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    public const ACTIVE = ['pending_payment', 'confirmed', 'used', 'frozen'];

    public const GAME_MODES = ['arcade'];

    protected $fillable = ['public_id', 'court_id', 'organizer_id', 'pricing_rule_id', 'starts_at', 'ends_at', 'status', 'price_cents', 'vat_rate', 'is_arena', 'hold_expires_at', 'cancelled_at', 'game_mode'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'is_arena' => 'boolean',
            'vat_rate' => 'decimal:2',
        ];
    }

    // Les URL n'exposent que l'identifiant public, jamais le numéro interne.
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id')->withTrashed();
    }

    public function players(): HasMany
    {
        return $this->hasMany(BookingPlayer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
