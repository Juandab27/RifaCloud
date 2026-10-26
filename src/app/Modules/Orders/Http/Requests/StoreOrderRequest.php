<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication is enforced by the auth:sanctum route middleware.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $maxNumbers = max(1, (int) config('rifacloud.order_max_numbers'));

        return [
            'raffle_id' => ['required', 'integer', 'exists:raffles,id'],
            'numbers' => ['required', 'array', 'min:1', "max:{$maxNumbers}"],
            'numbers.*' => ['required', 'integer', 'min:1', 'max:4294967295', 'distinct'],
        ];
    }
}
