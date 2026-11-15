<?php

namespace RifaCloud\AuthService\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Registration password policy: at least 8 characters, one uppercase letter
 * and one digit. Lowercase, special characters and long passwords are allowed;
 * no additional composition rule is imposed.
 */
final class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('La contraseña debe ser texto.');

            return;
        }

        if (mb_strlen($value) < 8) {
            $fail('La contraseña debe tener al menos 8 caracteres.');

            return;
        }

        if (preg_match('/[A-Z]/', $value) !== 1) {
            $fail('La contraseña debe incluir al menos una letra mayúscula.');

            return;
        }

        if (preg_match('/[0-9]/', $value) !== 1) {
            $fail('La contraseña debe incluir al menos un número.');
        }
    }
}
