<?php

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
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
            'customer_name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'customer_email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
            ],

            'customer_phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::enum(OrderStatus::class),
            ],

            'items' => [
                'sometimes',
                'required',
                'array',
                'min:1',
            ],

            'items.*.product_name' => [
                'required',
                'string',
                'max:255',
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'items.*.price' => [
                'required',
                'numeric',
                'min:0.01',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.min' => 'At least one order item is required.',

            'items.*.product_name.required' =>
                'The product name is required for every item.',

            'items.*.quantity.required' =>
                'The quantity is required for every item.',

            'items.*.quantity.integer' =>
                'The quantity must be an integer.',

            'items.*.quantity.min' =>
                'The quantity must be at least 1.',

            'items.*.price.required' =>
                'The price is required for every item.',

            'items.*.price.numeric' =>
                'The price must be a valid number.',

            'items.*.price.min' =>
                'The price must be at least 0.01.',
        ];
    }
}
