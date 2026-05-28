<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

trait ResolvesInternalPanelActor
{
    protected function resolveActor(Request $request): User
    {
        if ($request->user() instanceof User) {
            return $request->user();
        }

        if ($request->attributes->get('internal_admin_proxy')) {
            $id = (int) config('internal_sync.panel_system_user_id', 1);
            $user = User::query()->find($id);
            if ($user) {
                return $user;
            }
        }

        abort(401, 'Usuario de sistema no configurado para panel interno.');
    }
}
