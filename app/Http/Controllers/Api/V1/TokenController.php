<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TokenController extends Controller
{
    public const PLAYER_TOKEN_DAYS = 30;

    /** Connexion d'une application : échange l'e-mail et le mot de passe contre un jeton de joueur. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $user = User::where('email', $data['email'])->first();
        // Même message pour un e-mail inconnu et un mauvais mot de passe : l'API ne révèle pas qui est inscrit.
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Identifiants incorrects.']);
        }
        if ($user->status !== 'active' || $user->email_verified_at === null) {
            return response()->json(['message' => 'Votre compte doit être actif et votre e-mail confirmé.'], 403);
        }
        $expires = now()->addDays(self::PLAYER_TOKEN_DAYS);
        $token = $user->createToken($data['device_name'], ['bookings'], $expires);
        activity('api')->causedBy($user)->performedOn($token->accessToken)->event('token-created')->log('Jeton API créé');

        return response()->json(['token' => $token->plainTextToken, 'abilities' => ['bookings'], 'expires_at' => $expires->toIso8601String()], 201);
    }

    public function destroy(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();
        $token->delete();
        activity('api')->causedBy($request->user())->performedOn($token)->event('token-revoked')->log('Jeton API révoqué');

        return response()->noContent();
    }
}
