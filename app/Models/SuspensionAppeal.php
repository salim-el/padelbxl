<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** F29 : contestation d'une suspension par le joueur. */
class SuspensionAppeal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['account_status_history_id', 'user_id', 'message', 'status', 'decision_note', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function suspension(): BelongsTo
    {
        return $this->belongsTo(AccountStatusHistory::class, 'account_status_history_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
