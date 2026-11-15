<?php

namespace App\Modules\Auth\Http\Requests;

use App\Rules\ColombianMobile;
use App\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize equivalent Colombian mobile formats before validation so the
     * stored value is always the 10-digit national number. A malformed value is
     * left untouched and rejected by ColombianMobile with a clear message.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('phone')) {
            return;
        }

        $normalized = ColombianMobile::normalize($this->input('phone'));

        if ($normalized !== null) {
            $this->merge(['phone' => $normalized]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Laravel's RFC validation rejects malformed addresses without
            // imposing a narrow provider-specific pattern.
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'regex:/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', 'unique:users,email'],
            'phone' => ['required', 'string', new ColombianMobile()],
            'password' => [
                'required',
                'string',
                'max:4096',
                'confirmed',
                new StrongPassword(),
            ],
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
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.string' => 'El correo electrónico debe ser texto.',
            'email.email' => 'Ingresa un correo electrónico válido (por ejemplo, nombre@dominio.com).',
            'email.regex' => 'Ingresa un correo electrónico válido (por ejemplo, nombre@dominio.com).',
            'email.max' => 'El correo electrónico no puede superar 255 caracteres.',
            'email.unique' => 'Ya existe una cuenta con este correo electrónico.',
            'phone.required' => 'El teléfono celular es obligatorio.',
            'phone.string' => 'El teléfono celular debe ser texto.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.string' => 'La contraseña debe ser texto.',
            'password.max' => 'La contraseña es demasiado larga.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
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
