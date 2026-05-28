<?php

namespace App\Services\Registration;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class MemberRegistrationService
{
    /**
     * Cuenta pendiente reutilizable: sin verificar correo y sin activación pagada.
     */
    public function isReclaimable(User $user): bool
    {
        if ($user->canAccessAdminPanel()) {
            return false;
        }

        if ($user->email_verified_at !== null) {
            return false;
        }

        if ($user->activation_paid_at !== null) {
            return false;
        }

        return true;
    }

    /**
     * Resuelve si el email/documento bloquean el registro o hay una cuenta a reemplazar.
     *
     * @return array{reclaim: User|null, errors: array<string, array<int, string>>}
     */
    public function analyzeAvailability(string $email, string $documentId): array
    {
        $email = strtolower(trim($email));
        $documentId = trim($documentId);

        $byEmail = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $byDocument = User::query()->where('document_id', $documentId)->first();

        $errors = [];

        if ($byEmail && $byDocument && $byEmail->id !== $byDocument->id) {
            if (! $this->isReclaimable($byEmail)) {
                $errors['email'] = ['Este correo ya está registrado con otra cuenta activa o verificada.'];
            }
            if (! $this->isReclaimable($byDocument)) {
                $errors['document_id'] = ['Este CI / NIT ya está registrado con otra cuenta activa o verificada.'];
            }

            if ($errors !== []) {
                return ['reclaim' => null, 'errors' => $errors];
            }

            return [
                'reclaim' => null,
                'errors' => [
                    'email' => ['El correo y el CI/NIT pertenecen a registros distintos. Usa «Iniciar sesión» o contacta soporte.'],
                ],
            ];
        }

        $candidate = $byEmail ?? $byDocument;

        if (! $candidate) {
            return ['reclaim' => null, 'errors' => []];
        }

        if ($this->isReclaimable($candidate)) {
            return ['reclaim' => $candidate, 'errors' => []];
        }

        if ($byEmail && ! $this->isReclaimable($byEmail)) {
            $errors['email'] = ['Este correo ya está registrado. Inicia sesión o recupera tu contraseña.'];
        }

        if ($byDocument && ! $this->isReclaimable($byDocument)) {
            $errors['document_id'] = ['Este CI / NIT ya está registrado en el sistema.'];
        }

        if ($errors === [] && $candidate) {
            $errors['email'] = ['Ya existe una cuenta con estos datos. Inicia sesión.'];
        }

        return ['reclaim' => null, 'errors' => $errors];
    }

    public function reclaimAndDelete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });
    }
}
