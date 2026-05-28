<?php

namespace App\Console\Commands;

use App\Models\BinaryPlacement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Socios sin actividad MLM en el plazo configurado: borrado duro si no tienen colocados en el binario bajo ellos;
 * si son padre binario, solo se anonimiza la cuenta (no se rompe el árbol).
 */
class PurgeInactiveMlmMembersCommand extends Command
{
    protected $signature = 'mlm:purge-inactive-members {--dry-run : Solo listar candidatos}';

    protected $description = 'Elimina o anonimiza socios inactivos 365d (sin last_mlm_activity_at reciente)';

    public function handle(): int
    {
        $days = (int) config('mlm.inactive_member.days_without_activity', 365);
        $cutoff = Carbon::now()->subDays($days);

        $q = User::query()
            ->where('mlm_role', 'member')
            ->where(function ($w) {
                $w->whereNull('account_type')->orWhere('account_type', 'member');
            })
            ->where(function ($w) use ($cutoff) {
                $w->whereNull('last_mlm_activity_at')
                    ->orWhere('last_mlm_activity_at', '<', $cutoff);
            });

        $candidates = (clone $q)->get();
        $dry = (bool) $this->option('dry-run');

        $deleted = 0;
        $anonymized = 0;

        foreach ($candidates as $user) {
            if ($user->canAccessAdminPanel()) {
                continue;
            }

            if ($dry) {
                $this->line("Candidato #{$user->id} {$user->email} last={$user->last_mlm_activity_at}");

                continue;
            }

            DB::transaction(function () use ($user, &$deleted, &$anonymized) {
                $hasBinaryChildren = BinaryPlacement::query()
                    ->where('parent_user_id', $user->id)
                    ->exists();

                if ($hasBinaryChildren) {
                    $user->forceFill([
                        'name' => 'Cuenta inactiva eliminada',
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
            $this->info('Dry-run: '.$candidates->count().' candidatos revisados (admin excluidos en ejecución real).');

            return self::SUCCESS;
        }

        $this->info("Eliminados: {$deleted}; anonimizados (tenían pierna binaria): {$anonymized}");

        return self::SUCCESS;
    }
}
