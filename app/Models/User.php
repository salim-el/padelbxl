<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use PragmaRX\Google2FA\Google2FA;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    // Désinscription en soft delete : la colonne prévue par le schéma (T08) marque le compte désactivé.
    public const DELETED_AT = 'deactivated_at';

    // Mêmes valeurs par défaut que la base, pour un modèle tout juste créé.
    protected $attributes = ['role' => 'player', 'status' => 'active'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'account_type',
        'company_name',
        'vat_number',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** Vérifie un code de l'application d'authentification et refuse un code déjà utilisé (rejeu dans la même tranche de 30 s). */
    public function verifyTwoFactorCode(string $code): bool
    {
        if (! $this->hasTwoFactor()) {
            return false;
        }
        $counter = (new Google2FA)->verifyKeyNewer($this->two_factor_secret, $code, $this->two_factor_last_counter ?? 0);
        if ($counter === false) {
            return false;
        }
        // Mise à jour conditionnelle : deux requêtes simultanées avec le même code, une seule passe.
        $won = static::whereKey($this->id)
            ->where(fn ($q) => $q->whereNull('two_factor_last_counter')->orWhere('two_factor_last_counter', '<', $counter))
            ->update(['two_factor_last_counter' => $counter]) === 1;
        $this->two_factor_last_counter = $counter;

        return $won;
    }
}
