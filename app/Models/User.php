<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use PragmaRX\Google2FA\Google2FA;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    // Désinscription en soft delete : la colonne prévue par le schéma (T08) marque le compte désactivé.
    public const DELETED_AT = 'deactivated_at';

    /** Le personnel du club. La Gate « manage-club » (AppServiceProvider) s'appuie sur cette seule liste. */
    public const STAFF_ROLES = ['manager', 'admin'];

    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    /** F35 : seul l'administrateur gère les comptes du personnel et leurs rôles. */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

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
        'birth_date',
        'guardian_email',
        'level',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_canonical',
        'two_factor_secret',
        'stripe_customer_id',
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
            'birth_date' => 'date',
            'suspended_until' => 'datetime',
        ];
    }

    /**
     * La boîte aux lettres derrière l'adresse : la casse, un « +alias » et, chez Gmail, les points n'y changent rien.
     * shortcut: le « + » est retiré chez tous les fournisseurs ; un fournisseur où « a+b » est une autre boîte verrait deux adresses confondues.
     */
    public static function canonicalEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = explode('+', substr($email, 0, $at), 2)[0];
        $domain = substr($email, $at + 1);
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            [$local, $domain] = [str_replace('.', '', $local), 'gmail.com'];
        }

        return $local.'@'.$domain;
    }

    // Un mutateur et non un événement : anonymize() enregistre sans événements (saveQuietly).
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => ['email' => $value, 'email_canonical' => self::canonicalEmail($value)]);
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

    /**
     * Remplace les données personnelles du compte (RGPD, art. 17). Les factures gardent leur copie du nom :
     * le Code de la TVA impose de les conserver sept ans.
     */
    public function anonymize(): void
    {
        // L'adresse tapée par l'organisateur reste dans booking_players : elle disparaît aussi.
        BookingPlayer::whereNotNull('guest_email')
            ->where(fn ($q) => $q->where('user_id', $this->id)
                ->orWhere(fn ($q) => $q->whereNull('user_id')->whereRaw('lower(guest_email) = ?', [mb_strtolower($this->email)])))
            ->update(['guest_email' => "supprime-{$this->id}@padelbxl.invalid"]);

        $this->forceFill([
            'name' => 'Compte supprimé',
            'email' => "supprime-{$this->id}@padelbxl.invalid",
            'phone' => null,
            'birth_date' => null,
            'guardian_email' => null,
            'account_type' => 'individual',
            'company_name' => null,
            'vat_number' => null,
            'password' => Str::random(64),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_counter' => null,
            'status' => 'anonymized',
            'anonymized_at' => now(),
        ])->saveQuietly();
        // Plus aucun jeton d'API ne doit ouvrir ce compte.
        $this->tokens()->delete();
    }
}
