<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
class StoreMemberRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('email')) {
            $merge['email'] = strtolower(trim((string) $this->input('email')));
        }

        if ($this->has('name')) {
            $merge['name'] = trim((string) $this->input('name'));
        }

        if ($this->has('document_id')) {
            $merge['document_id'] = trim((string) $this->input('document_id'));
        }

        $birth = $this->input('birth_date');
        if (is_string($birth) && $birth !== '') {
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $birth, $m)) {
                $merge['birth_date'] = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            } else {
                $merge['birth_date'] = trim($birth);
            }
        }

        $code = $this->input('country_code');
        if ($code === null || $code === '') {
            $merge['country_code'] = null;
        } else {
            $merge['country_code'] = strtoupper(trim((string) $code));
        }

        if ($this->has('phone')) {
            $merge['phone'] = preg_replace('/\s+/', '', (string) $this->input('phone'));
        }

        if ($this->has('sponsor_referral_code')) {
            $merge['sponsor_referral_code'] = trim((string) $this->input('sponsor_referral_code')) ?: null;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
            'document_id' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'birth_date' => ['required', 'date', 'date_format:Y-m-d', 'before:today'],
            'sponsor_referral_code' => ['nullable', 'string', 'max:32'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'registration_package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'preferred_payment_method' => ['nullable', 'string', 'max:32'],
            'preferred_binary_leg' => ['nullable', 'string', 'in:left,right,auto'],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.date_format' => 'La fecha de nacimiento debe ser válida.',
            'birth_date.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'country_id.exists' => 'El país seleccionado no existe en el catálogo del servidor.',
            'country_code.size' => 'Selecciona un país válido (código de 2 letras).',
            'preferred_binary_leg.in' => 'Colocación binaria inválida (izquierda, derecha o automático).',
            'registration_package_id.exists' => 'El paquete de inscripción no existe en el catálogo.',
            'sponsor_referral_code.max' => 'El código de patrocinador es demasiado largo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre completo',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'password_confirmation' => 'confirmación de contraseña',
            'document_id' => 'CI / NIT',
            'phone' => 'teléfono',
            'birth_date' => 'fecha de nacimiento',
            'sponsor_referral_code' => 'código de patrocinador',
            'country_id' => 'país',
            'country_code' => 'país',
            'registration_package_id' => 'paquete de inscripción',
            'preferred_binary_leg' => 'colocación binaria',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $message = $this->formatErrorMessage($errors);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $errors,
                'data' => ['errors' => $errors],
            ], 422)
        );
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function formatErrorMessage(array $errors): string
    {
        if ($errors === []) {
            return 'Revisa los datos del formulario.';
        }

        $parts = [];
        foreach ($errors as $field => $messages) {
            $label = match ($field) {
                'email' => 'Correo',
                'document_id' => 'CI / NIT',
                'password' => 'Contraseña',
                'password_confirmation' => 'Confirmar contraseña',
                'country_code', 'country_id' => 'País',
                'sponsor_referral_code' => 'Patrocinador',
                'registration_package_id' => 'Paquete',
                'birth_date' => 'Fecha de nacimiento',
                'phone' => 'Teléfono',
                default => $field,
            };
            $parts[] = $label.': '.($messages[0] ?? '');
        }

        return implode(' ', $parts);
    }
}
