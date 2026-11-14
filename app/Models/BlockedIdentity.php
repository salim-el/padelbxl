<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** F28 : empreinte d'un e-mail révoqué pour fraude. L'adresse elle-même n'est pas gardée. */
class BlockedIdentity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['email_hash', 'card_fingerprint', 'reason'];

    public static function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    public static function blocksEmail(string $email): bool
    {
        return static::where('email_hash', static::hashEmail($email))->exists();
    }
}
