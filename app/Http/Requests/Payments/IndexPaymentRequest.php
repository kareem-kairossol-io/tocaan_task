<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentStatus;
use App\Payments\PaymentGatewayFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'sometimes',
                'nullable',
                Rule::enum(PaymentStatus::class),
            ],

            'method' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in($this->availableMethods()),
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],

            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function availableMethods(): array
    {
        return app(PaymentGatewayFactory::class)
            ->availableMethods();
    }
}
