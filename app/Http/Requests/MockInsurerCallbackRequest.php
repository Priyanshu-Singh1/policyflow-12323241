<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MockInsurerCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:255'],
            'provider_reference' => ['required', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'in:POLICY_ISSUED,POLICY_FAILED'],
            'policy_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'error' => 'VALIDATION_FAILED',
                'message' => 'The callback payload failed validation.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
