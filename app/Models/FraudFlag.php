<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** F30 et F18 : signalement d'un comportement suspect. Une personne l'examine ; il ne sanctionne jamais seul (RGPD, art. 22). */
class FraudFlag extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'details', 'status', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
