<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    /** Seuls le nom affiché et la langue changent par l'API. L'e-mail et le mot de passe restent sur le site. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'locale' => ['sometimes', Rule::in(SetLocale::LOCALES)],
        ]);
        $user = $request->user();
        $user->forceFill($data)->save();

        return response()->json(['data' => $this->present($user)]);
    }

    private function present(User $u): array
    {
        return ['name' => $u->name, 'email' => $u->email, 'locale' => $u->locale, 'account_type' => $u->account_type];
    }
}
