<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string'],
            'status' => ['required', 'string', 'in:quoted,accepted,rejected,completed'],
            'final_amount' => ['nullable', 'numeric', 'min:0'],
            'quote_ref' => ['nullable', 'string', 'max:255'],
            'signed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $status = $this->input('status');

            // Le montant est requis pour accepted et completed
            if (in_array($status, ['accepted', 'completed'])) {
                if (empty($this->input('final_amount'))) {
                    $validator->errors()->add(
                        'final_amount',
                        'Le montant final est requis pour le statut ' . $status
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'session_id.required' => 'L\'ID de session est obligatoire',
            'status.required' => 'Le statut est obligatoire',
            'status.in' => 'Statut invalide (quoted, accepted, rejected, completed)',
            'final_amount.numeric' => 'Le montant doit être un nombre',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $firstField = $errors->keys()[0] ?? 'unknown';
        $firstMessage = $errors->first();

        throw new HttpResponseException(response()->json([
            'error' => 'validation_error',
            'message' => $firstMessage,
            'field' => $firstField,
        ], 400));
    }
}
