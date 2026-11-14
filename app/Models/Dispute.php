<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** F17 : demande exceptionnelle d'un joueur sur une réservation ; F27 : décision motivée du gérant. */
class Dispute extends Model
{
    public const UPDATED_AT = null;

    public const OPEN = ['received', 'in_review'];

    public const DECISIONS = ['accepted_full', 'accepted_partial', 'rejected'];

    protected $fillable = ['booking_id', 'user_id', 'reason', 'attachment_path', 'status', 'decision_note', 'refunded_cents', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
