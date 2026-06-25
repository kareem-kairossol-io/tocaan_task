<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
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
                'required',
                'string',
                'max:255',
            ],

            'customer_email' => [
                'required',
                'email',
                'max:255',
            ],

            'customer_phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'items' => [
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
            'items.required' => 'At least one order item is required.',
            'items.array' => 'The items field must be an array.',
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
