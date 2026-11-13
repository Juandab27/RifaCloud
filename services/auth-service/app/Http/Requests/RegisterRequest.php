<?php

namespace RifaCloud\AuthService\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // S11: privacy and terms are mandatory; promotional consent is
            // optional and must never be bundled with the required boxes.
            'privacy_consent' => ['required', 'accepted'],
            'terms_consent' => ['required', 'accepted'],
            'consent_version' => ['required', 'string', Rule::in([(string) config('rifacloud.legal.consent_version')])],
            'marketing_consent' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'privacy_consent.required' => 'Debes aceptar la Política de Tratamiento de Datos Personales.',
            'privacy_consent.accepted' => 'Debes aceptar la Política de Tratamiento de Datos Personales.',
            'terms_consent.required' => 'Debes aceptar los Términos y Condiciones del prototipo.',
            'terms_consent.accepted' => 'Debes aceptar los Términos y Condiciones del prototipo.',
            'consent_version.required' => 'Falta la versión de los documentos legales.',
            'consent_version.in' => 'La versión de los documentos legales no está vigente. Recarga la página e inténtalo de nuevo.',
            'marketing_consent.boolean' => 'El consentimiento promocional debe ser verdadero o falso.',
        ];
    }
}
