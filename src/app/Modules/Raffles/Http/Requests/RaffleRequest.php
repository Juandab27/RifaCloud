<?php

namespace App\Modules\Raffles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RaffleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role enforcement happens in the "admin" route middleware (NFR-012).
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price_per_number' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'min_number' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'max_number' => ['required', 'integer', 'min:1', 'max:4294967295', 'gte:min_number'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    /**
     * Technical guard for number generation, not a business rule.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $min = (int) $this->input('min_number');
                $max = (int) $this->input('max_number');
                $limit = (int) config('rifacloud.raffle_max_numbers');

                if ($max >= $min && ($max - $min + 1) > $limit) {
                    $validator->errors()->add(
                        'max_number',
                        "The number range cannot exceed {$limit} numbers.",
                    );
                }
            },
        ];
    }
}
