<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled by middleware
    }

    public function rules(): array
    {
        return [
            'external_ref' => ['required', 'string', 'max:255'],
            'agent_slug' => ['nullable', 'string', 'max:100'],

            'client' => ['nullable', 'array'],
            'client.name' => ['nullable', 'string', 'max:255'],
            'client.phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'client.email' => ['nullable', 'email', 'max:255'],
            'client.address' => ['nullable', 'string', 'max:500'],

            'send_via' => ['nullable', 'string', 'in:sms,email,both,none'],
            'message_template' => ['nullable', 'string', 'in:default,custom'],

            'metadata' => ['nullable', 'array'],

            'options' => ['nullable', 'array'],
            'options.expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'options.max_uses' => ['nullable', 'integer', 'min:1', 'max:100'],
            'options.is_marketplace_lead' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sendVia = $this->input('send_via', 'none');

            // Si SMS, le téléphone est requis
            if (in_array($sendVia, ['sms', 'both'])) {
                if (empty($this->input('client.phone'))) {
                    $validator->errors()->add(
                        'client.phone',
                        'Le téléphone est requis pour l\'envoi par SMS'
                    );
                }
            }

            // Si Email, l'email est requis
            if (in_array($sendVia, ['email', 'both'])) {
                if (empty($this->input('client.email'))) {
                    $validator->errors()->add(
                        'client.email',
                        'L\'email est requis pour l\'envoi par email'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'external_ref.required' => 'La référence externe est obligatoire',
            'client.phone.regex' => 'Format de téléphone invalide',
            'client.email.email' => 'Format d\'email invalide',
            'send_via.in' => 'Canal d\'envoi invalide (sms, email, both, none)',
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
