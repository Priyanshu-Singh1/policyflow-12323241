<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreatePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:255'],
            'agent_id' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'destination' => ['required', 'string', 'in:DOMESTIC,INTERNATIONAL'],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', 'in:BASIC,PLUS,PREMIUM'],
            'sum_insured' => ['required', 'integer', 'in:100000,300000,500000'],
        ];
    }

    public function messages(): array
    {
        return [
            'trip_end.after_or_equal' => 'The trip_end date must be greater than or equal to trip_start.',
            'plan_code.in' => 'Supported plan codes are: BASIC, PLUS, PREMIUM.',
            'destination.in' => 'Supported destinations are: DOMESTIC, INTERNATIONAL.',
            'sum_insured.in' => 'Supported sum_insured values are: 100000, 300000, 500000.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'error' => 'VALIDATION_FAILED',
                'message' => 'The policy intake request contains invalid fields.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
