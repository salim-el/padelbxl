<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** F28 : un changement d'état d'un compte, avec son motif. */
class AccountStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'from_status', 'to_status', 'reason', 'until', 'changed_by'];

    protected function casts(): array
    {
        return ['until' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function appeal(): HasOne
    {
        return $this->hasOne(SuspensionAppeal::class);
    }
}
