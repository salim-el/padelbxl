<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** F15 : avoir d'un joueur, né d'un remboursement, d'une demande exceptionnelle ou d'un geste du club. */
class StoreCredit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'amount_cents', 'remaining_cents', 'origin', 'refund_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }

    /** Avoirs encore utilisables : pas expirés, avec un reste. */
    public function scopeUsable(Builder $query): void
    {
        $query->where('remaining_cents', '>', 0)->where('expires_at', '>', now());
    }

    public function usages(): HasMany
    {
        return $this->hasMany(StoreCreditUsage::class);
    }
}
