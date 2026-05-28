<?php

namespace App\Console\Commands;

use App\Models\BinaryPlacement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Limpieza de registros no finalizados:
 * - Sin verificación de correo en 24h: eliminado (o anonimizado si ya es padre en binario).
 * - Socio sin pago de activación (activation_paid_at null) en 24h: eliminado (o anonimizado si ya es padre en binario).
 */
class PruneStaleRegistrationsCommand extends Command
{
    protected $signature = 'mlm:prune-stale-registrations {--hours=24 : Ventana en horas} {--dry-run : Solo listar candidatos}';

    protected $description = 'Elimina cuentas sin verificación de email o sin pago de activación en la ventana configurada';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        if ($hours < 1) {
            $hours = 24;
        }
        $cutoff = Carbon::now()->subHours($hours);
        $dry = (bool) $this->option('dry-run');

        $unverified = User::query()
            ->whereNull('email_verified_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        $unpaid = User::query()
            ->where(function ($w) {
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            })
            ->whereNull('activation_paid_at')
            ->where('created_at', '<', $cutoff)
            ->get();

        $candidates = $unverified
            ->merge($unpaid)
            ->unique('id')
            ->values();

        $deleted = 0;
        $anonymized = 0;

        foreach ($candidates as $user) {
            if ($user->canAccessAdminPanel()) {
                continue;
            }
            if ($dry) {
                $this->line("Candidato #{$user->id} {$user->email} created={$user->created_at} verified=".($user->email_verified_at ? 'yes' : 'no')." paid=".($user->activation_paid_at ? 'yes' : 'no'));
                continue;
            }

            DB::transaction(function () use ($user, &$deleted, &$anonymized) {
                $hasBinaryChildren = BinaryPlacement::query()
                    ->where('parent_user_id', $user->id)
                    ->exists();

                if ($hasBinaryChildren) {
                    $user->forceFill([
                        'name' => 'Cuenta eliminada',
                        'email' => 'purged_'.$user->id.'_'.time().'@invalid.local',
                        'referral_code' => null,
                        'member_code' => null,
                        'password' => bcrypt(Str::random(40)),
                        'account_status' => 'inactive',
                    ])->save();
                    $anonymized++;
                    return;
                }

                $user->delete();
                $deleted++;
            });
        }

        if ($dry) {
            $this->info('Dry-run: '.$candidates->count().' candidatos (admins excluidos en ejecución real).');
            return self::SUCCESS;
        }

        $this->info("Eliminados: {$deleted}; anonimizados: {$anonymized}");
        return self::SUCCESS;
    }
}

