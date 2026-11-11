<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],

            // Changer d'adresse, c'est changer l'identifiant du compte : on redemande le mot de passe.
            'current_password' => [
                Rule::requiredIf(fn () => mb_strtolower((string) $this->input('email')) !== mb_strtolower($this->user()->email)),
                'nullable',
                'current_password',
            ],
        ];
    }
}
