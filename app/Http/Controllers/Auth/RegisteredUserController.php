<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\BlockedIdentity;
use App\Models\User;
use App\Rules\NotDisposableEmail;
use App\Services\LegalConsents;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['vat_number' => self::normalizeVat($request->input('vat_number'))]);
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class, new NotDisposableEmail, function (string $attribute, mixed $value, \Closure $fail) {
                if (BlockedIdentity::blocksEmail((string) $value)) {
                    $fail(__('Cette adresse ne peut pas être utilisée pour créer un compte.'));
                }
            }],
            // F1 : la date de naissance applique la règle des 13 ans ; le niveau sert aux appels à joueurs (F11).
            'birth_date' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'level' => ['required', 'numeric', 'between:1,7'],
            'guardian_email' => [Rule::requiredIf(fn () => self::isUnder13($request->input('birth_date'))), 'nullable', 'email', 'max:255', 'different:email'],
            'accept_terms' => ['accepted'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'account_type' => ['required', 'in:individual,company'],
            'company_name' => ['exclude_unless:account_type,company', 'required', 'string', 'max:120'],
            'vat_number' => ['exclude_unless:account_type,company', 'required', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::isValidBelgianVat($value)) {
                    $fail('Ce numéro de TVA belge n’est pas valide (format BE0123456789).');
                }
            }],
        ]);
        $company = $request->account_type === 'company';

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'account_type' => $request->account_type,
            'company_name' => $company ? $request->company_name : null,
            'vat_number' => $company ? $request->vat_number : null,
            'birth_date' => $request->birth_date,
            'level' => $request->level,
            'guardian_email' => self::isUnder13($request->birth_date) ? $request->guardian_email : null,
        ]);
        // F1 : la version acceptée des conditions et de la vie privée est enregistrée avec la date.
        app(LegalConsents::class)->accept($user, $request->ip());

        event(new Registered($user));

        Auth::login($user);

        return to_route('dashboard');
    }

    /** Moins de 13 ans : le compte est créé par un parent, qui donne son adresse (RGPD, art. 8, et loi belge du 30/07/2018). */
    private static function isUnder13(mixed $birthDate): bool
    {
        try {
            return is_string($birthDate) && $birthDate !== '' && CarbonImmutable::parse($birthDate)->addYears(13)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /** « be 0123.456.749 » devient « BE0123456749 » ; un numéro à 9 chiffres reçoit son 0 initial. */
    private static function normalizeVat(?string $vat): ?string
    {
        if ($vat === null || trim($vat) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $vat);

        return 'BE'.(strlen($digits) === 9 ? '0'.$digits : $digits);
    }

    /** Numéro d'entreprise belge : 10 chiffres commençant par 0 ou 1, les deux derniers = 97 − (les 8 premiers modulo 97). */
    private static function isValidBelgianVat(?string $vat): bool
    {
        if ($vat === null || ! preg_match('/^BE([01]\d{7})(\d{2})$/', $vat, $m)) {
            return false;
        }

        return 97 - ((int) $m[1] % 97) === (int) $m[2];
    }
}
