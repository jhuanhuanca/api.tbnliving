<?php

namespace App\Services\Admin;

use App\Models\User;

class AdminPanelAuthService
{
    public function formatAdminUser(User $user): array
    {
        $role = (string) ($user->mlm_role ?? 'admin');
        $labels = (array) config('admin_panel.role_labels', []);
        $permsMap = (array) config('admin_panel.permissions_by_role', []);

        $roleLabel = $labels[$role] ?? ucfirst($role);
        $permissions = $permsMap[$role] ?? ($permsMap['admin'] ?? []);

        return [
            'id' => $user->id,
            'uuid' => $user->uuid ?? null,
            'name' => $user->name,
            'lastname' => $user->lastname ?? null,
            'email' => $user->email,
            'mlm_role' => $role,
            'roles' => [$roleLabel],
            'permissions' => $permissions,
        ];
    }

    public function assertCanAccessPanel(User $user): void
    {
        if (! $user->canAccessAdminPanel()) {
            abort(403, 'Tu cuenta no tiene acceso al panel administrativo.');
        }
    }
}
