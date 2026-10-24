<?php

namespace App\Http\Requests;

use App\Enums\SessionOfferType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveSessionOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active && ($this->user()->isTherapist() || $this->user()->isAdministrator());
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'type' => ['required', Rule::enum(SessionOfferType::class)],
            'sessions_count' => ['required', 'integer', 'between:1,100'],
            'price_clp' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'active' => ['required', 'boolean'],
            'therapist_profile_id' => $this->user()->isAdministrator() && $this->isMethod('post')
                ? ['required', 'integer', 'exists:therapist_profiles,id'] : ['exclude'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $count = (int) $this->input('sessions_count');
            if (($this->input('type') === SessionOfferType::Single->value && $count !== 1)
                || ($this->input('type') === SessionOfferType::Pack->value && $count < 2)) {
                $validator->errors()->add('sessions_count', 'Una sesión individual contiene exactamente 1 sesión; un pack contiene entre 2 y 100 sesiones.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'type.enum' => 'Selecciona sesión individual o pack.',
            'price_clp.*' => 'Ingresa un precio entero en pesos chilenos, entre $1 y $2.147.483.647.',
            'sessions_count.*' => 'Indica una cantidad entera entre 1 y 100 sesiones.',
            'name.required' => 'Escribe el nombre de la oferta.',
            'therapist_profile_id.required' => 'Selecciona el apiterapeuta vendedor.',
        ];
    }
}
