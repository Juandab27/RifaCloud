<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Colombian mobile number validation and normalization.
 *
 * Accepts the equivalent human formats 3001234567, 300 123 4567,
 * +57 300 123 4567, +573001234567 and 573001234567. The stored value is
 * always the 10-digit national number (starting with 3), which fits the
 * existing users.phone VARCHAR(20) column. No SMS verification is implied.
 */
final class ColombianMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::normalize($value) === null) {
            $fail('Ingresa un celular colombiano de 10 dígitos que empiece por 3 (por ejemplo, 300 123 4567).');
        }
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = trim($value);

        if ($candidate === '' || mb_strlen($candidate) > 30) {
            return null;
        }

        // Only digits and common separators, optionally with a leading +.
        if (preg_match('/^\+?[0-9(][0-9\s().-]*$/', $candidate) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $candidate);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if ($candidate[0] === '+') {
            if (! str_starts_with($digits, '57')) {
                return null;
            }

            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '57')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^3[0-9]{9}$/', $digits) === 1 ? $digits : null;
    }
}
