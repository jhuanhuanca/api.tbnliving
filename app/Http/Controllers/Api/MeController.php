<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BinaryLegVolumeWeekly;
use App\Models\BinaryPlacement;
use App\Models\BinaryDailyPayout;
use App\Models\BinaryWeeklyBonus;
use App\Models\BinaryWeeklyCarry;
use App\Models\CommissionEvent;
use App\Models\Rank;
use App\Models\Order;
use App\Models\User;
use App\Services\Member\UserProfileService;
use App\Services\BinaryService;
use App\Services\CareerRankService;
use App\Services\LeadershipRequalificationService;
use App\Services\MlmBonusProgressService;
use App\Services\MonthlyActivationService;
use App\Services\UserQualificationService;
use App\Services\WalletService;
use App\Support\FounderPackages;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function profile(Request $request, UserProfileService $userProfileService)
    {
        return response()->json($userProfileService->buildFromRequest($request));
    }

    public function dashboard(
        Request $request,
        WalletService $walletService,
        BinaryService $binaryService,
        MlmBonusProgressService $bonusProgress,
        CareerRankService $careerRankService,
        LeadershipRequalificationService $leadershipRequalification,
        UserQualificationService $qualificationService,
        MonthlyActivationService $monthlyActivation
    )
    {
        /** @var User $user */
        $user = $request->user()->loadMissing('rank', 'sponsor', 'country', 'registrationPackage');
        $user = $qualificationService->syncMonthlyQualificationIfStale($user);
        $requiredActivationPv = $monthlyActivation->requiredPvForUser($user);

        $periodKey = $binaryService->volumePeriodKey(now());
        $prevKey = $binaryService->previousVolumePeriodKey($periodKey);

        $leftPv = (string) BinaryLegVolumeWeekly::query()
            ->where('parent_user_id', $user->id)
            ->where('week_key', $periodKey)
            ->where('leg_position', 'left')
            ->value('volume_pv') ?? '0';

        $rightPv = (string) BinaryLegVolumeWeekly::query()
            ->where('parent_user_id', $user->id)
            ->where('week_key', $periodKey)
            ->where('leg_position', 'right')
            ->value('volume_pv') ?? '0';

        $carry = BinaryWeeklyCarry::query()
            ->where('user_id', $user->id)
            ->where('week_key', $prevKey)
            ->first();

        $directCount = User::query()->where('sponsor_id', $user->id)->count();

        $commissionsTotal = (string) CommissionEvent::query()
            ->where('beneficiary_user_id', $user->id)
            ->sum('amount');

        $user->loadMissing('registrationPackage', 'referrals.rank');
        $display = $this->displayRankTuple($user, $careerRankService);
        $nextStep = $careerRankService->describeNextCareerStep($user);
        $rankAccumulatedPv = $careerRankService->rankAccumulatedPv($user);
        $monthKey = now()->format('Y-m');
        $leadershipState = $display['id'] ? $leadershipRequalification->statusFor((int) $user->id, (int) $display['id'], $monthKey) : null;

        return response()->json([
            'commissions_total' => $commissionsTotal,
            'bonus_progress' => $bonusProgress->resumen($user),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'referral_code' => $user->referral_code,
                'member_code' => $user->member_code,
                'document_id' => $user->document_id,
                'phone' => $user->phone,
                'country_code' => $user->country_code,
                'country' => $user->country?->toApiArray(),
                'is_mlm_qualified' => $user->is_mlm_qualified,
                'monthly_qualifying_pv' => $user->monthly_qualifying_pv,
                'monthly_activation_required_pv' => $requiredActivationPv,
                'account_status' => $user->account_status,
                'rank_name' => $display['name'],
                // NUEVO: métrica separada para carrera de rango (directos * 60%).
                'rank_accumulated_pv' => $rankAccumulatedPv,
            ],
            // Rango mostrado: mayor entre persistido (rank_id) y elegible por reglas del periodo.
            'rank' => [
                'id' => $display['id'],
                'name' => $display['name'],
                'slug' => $display['slug'],
                // NUEVO: progreso hacia siguiente rango (compat hacia atrás: solo añade claves).
                'rank_accumulated_pv' => $rankAccumulatedPv,
                'next_rank_requirement' => $nextStep['next_min_group_pv'] ?? null,
                'rank_progress_percentage' => $nextStep['percent_pv'] ?? null,
            ],
            'sponsor' => $user->sponsor ? [
                'name' => $user->sponsor->name,
                'referral_code' => $user->sponsor->referral_code,
            ] : null,
            'wallet' => [
                'available' => $walletService->saldoDisponible($user),
            ],
            'referrals_direct_count' => $directCount,
            'binary_week' => [
                'week_key' => $periodKey,
                'volume_period' => $binaryService->isMonthlyBinaryVolume() ? 'monthly' : 'weekly',
                'left_pv' => $leftPv,
                'right_pv' => $rightPv,
                'left_carry_in' => $carry ? (string) $carry->left_carry_pv : '0',
                'right_carry_in' => $carry ? (string) $carry->right_carry_pv : '0',
            ],
            // NUEVO: bloque explícito (futuro-proof para dashboards BI)
            'rank_progress' => [
                'rank_accumulated_pv' => $rankAccumulatedPv,
                'next_rank_requirement' => $nextStep['next_min_group_pv'] ?? null,
                'rank_progress_percentage' => $nextStep['percent_pv'] ?? null,
                'next_rank_slug' => $nextStep['next_slug'] ?? null,
                'missing_messages' => $nextStep['missing_messages'] ?? [],
            ],
            'leadership' => [
                'month_key' => $monthKey,
                'leadership_requalification_count' => $leadershipState['leadership_requalification_count'] ?? 0,
                'remaining_requalifications' => $leadershipState['remaining_requalifications'] ?? 2,
                'leadership_bonus_eligibility' => $leadershipState['leadership_bonus_eligibility'] ?? false,
                'current_rank_progress' => [
                    'rank_accumulated_pv' => $rankAccumulatedPv,
                    'required_pv' => $nextStep['next_min_group_pv'] ?? null,
                    'percent' => $nextStep['percent_pv'] ?? null,
                ],
            ],
        ]);
    }

    public function referrals(Request $request, CareerRankService $careerRankService)
    {
        $rows = User::query()
            ->where('sponsor_id', $request->user()->id)
            ->with(['rank:id,name,slug', 'binaryPlacement', 'registrationPackage', 'referrals.rank'])
            ->orderByDesc('created_at')
            ->get();

        $rankNamesBySlug = Rank::query()->pluck('name', 'slug')->all();

        $items = $rows->map(function (User $u) use ($careerRankService, $rankNamesBySlug) {
            $leg = $u->binaryPlacement?->leg_position;
            $u->loadMissing('rank', 'registrationPackage', 'referrals.rank');
            $slug = $careerRankService->displayRankSlug($u);
            $dispName = (string) ($rankNamesBySlug[$slug] ?? $slug);

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'referral_code' => $u->referral_code,
                'member_code' => $u->member_code,
                'preferred_binary_leg' => $u->preferred_binary_leg,
                'pierna' => $leg,
                'fecha_alta' => $u->created_at?->format('d/m/Y'),
                'joined_at' => $u->created_at?->toIso8601String(),
                'monthly_qualifying_pv' => $u->monthly_qualifying_pv,
                'account_status' => $u->account_status,
                'is_mlm_qualified' => (bool) $u->is_mlm_qualified,
                'rank_name' => $dispName !== '' ? $dispName : '—',
                'rank_slug' => $slug,
            ];
        });

        $activos = $items->where('is_mlm_qualified', true)->count();
        $pendientes = $items->where('account_status', 'pending')->count();
        $izq = $items->where('pierna', BinaryPlacement::LEG_LEFT)->count();
        $der = $items->where('pierna', BinaryPlacement::LEG_RIGHT)->count();

        return response()->json([
            'items' => $items->values(),
            'summary' => [
                'total' => $items->count(),
                'activos' => $activos,
                'pendientes' => $pendientes,
                'izquierda' => $izq,
                'derecha' => $der,
            ],
        ]);
    }

    /**
     * Árbol UNILEVEL hasta N generaciones (por sponsor_id).
     * Retorna niveles (1..depth) y una estructura children_by_sponsor para render.
     */
    public function unilevelTree(Request $request, CareerRankService $careerRankService)
    {
        /** @var User $me */
        $me = $request->user();

        $depth = (int) ($request->query('depth', 3));
        if ($depth < 1) {
            $depth = 1;
        }
        if ($depth > 6) {
            $depth = 6; // límite defensivo
        }

        $levels = [];
        $childrenBySponsor = [];

        $currentSponsorIds = [$me->id];
        $rankNamesBySlug = Rank::query()->pluck('name', 'slug')->all();

        for ($gen = 1; $gen <= $depth; $gen++) {
            $rows = User::query()
                ->whereIn('sponsor_id', $currentSponsorIds)
                ->with(['rank:id,name,slug', 'registrationPackage', 'referrals.rank'])
                ->orderBy('created_at')
                ->get();

            $items = $rows->map(function (User $u) use ($careerRankService, $rankNamesBySlug) {
                $u->loadMissing('rank', 'registrationPackage', 'referrals.rank');
                $slug = $careerRankService->displayRankSlug($u);
                $dispName = (string) ($rankNamesBySlug[$slug] ?? $slug);

                return [
                    'id' => $u->id,
                    'sponsor_id' => $u->sponsor_id,
                    'name' => $u->name,
                    'member_code' => $u->member_code,
                    'joined_at' => $u->created_at?->toIso8601String(),
                    'fecha_alta' => $u->created_at?->format('d/m/Y'),
                    'monthly_qualifying_pv' => $u->monthly_qualifying_pv,
                    'account_status' => $u->account_status,
                    'is_mlm_qualified' => (bool) $u->is_mlm_qualified,
                    'rank_name' => $dispName !== '' ? $dispName : '—',
                    'rank_slug' => $slug,
                ];
            })->values();

            $levels[(string) $gen] = $items;

            foreach ($items as $it) {
                $sid = (string) ($it['sponsor_id'] ?? 0);
                if (! isset($childrenBySponsor[$sid])) {
                    $childrenBySponsor[$sid] = [];
                }
                $childrenBySponsor[$sid][] = $it;
            }

            $currentSponsorIds = $rows->pluck('id')->all();
            if (empty($currentSponsorIds)) {
                break;
            }
        }

        return response()->json([
            'depth' => $depth,
            'root' => [
                'id' => $me->id,
                'name' => $me->name,
                'member_code' => $me->member_code,
            ],
            'levels' => $levels,
            'children_by_sponsor' => $childrenBySponsor,
        ]);
    }

    /**
     * Vista de árbol binario (2 niveles de profundidad) + KPIs para el front.
     */
    public function binaryTree(Request $request, BinaryService $binaryService, WalletService $walletService, CareerRankService $careerRankService)
    {
        /** @var User $user */
        $user = $request->user()->loadMissing('rank');
        $periodKey = $binaryService->volumePeriodKey(now());

        $leftPv = (string) BinaryLegVolumeWeekly::query()
            ->where('parent_user_id', $user->id)
            ->where('week_key', $periodKey)
            ->where('leg_position', 'left')
            ->value('volume_pv') ?? '0';

        $rightPv = (string) BinaryLegVolumeWeekly::query()
            ->where('parent_user_id', $user->id)
            ->where('week_key', $periodKey)
            ->where('leg_position', 'right')
            ->value('volume_pv') ?? '0';

        $binaryEarningsWeek = (string) CommissionEvent::query()
            ->where('beneficiary_user_id', $user->id)
            ->where('type', 'binary')
            ->where('period_key', $periodKey)
            ->sum('amount');

        $birByLevel = [];
        for ($level = 1; $level <= 3; $level++) {
            $birByLevel[$level] = (string) CommissionEvent::query()
                ->where('beneficiary_user_id', $user->id)
                ->where('type', 'bir')
                ->where('level', $level)
                ->sum('amount');
        }

        $birTotal = (string) CommissionEvent::query()
            ->where('beneficiary_user_id', $user->id)
            ->where('type', 'bir')
            ->sum('amount');

        $residualTotal = (string) CommissionEvent::query()
            ->where('beneficiary_user_id', $user->id)
            ->where('type', 'residual')
            ->sum('amount');

        $user->loadMissing('registrationPackage', 'referrals.rank');
        $display = $this->displayRankTuple($user, $careerRankService);
        $rankName = $display['name'];

        return response()->json([
            'week_key' => $periodKey,
            'volume_period' => $binaryService->isMonthlyBinaryVolume() ? 'monthly' : 'weekly',
            'wallet_available' => $walletService->saldoDisponible($user),
            'stats' => [
                'left_pv' => $leftPv,
                'right_pv' => $rightPv,
                'binary_earnings_week' => $binaryEarningsWeek,
                'bir_total' => $birTotal,
                'bir_by_level' => $birByLevel,
                'residual_total' => $residualTotal,
                'current_rank' => $rankName,
                'binary_network_count' => $this->countBinaryDownlineUsers($user->id),
            ],
            'bir_percentages' => config('mlm.bir.schedule', []),
            'tree' => $this->binarySubtreePayload($user->id, 2),
        ]);
    }

    /**
     * Lazy loading del árbol binario: devuelve left/right directos de un parent (por defecto, el usuario autenticado).
     *
     * Forma del nodo:
     * {
     *   id, name, code, phone, is_active,
     *   left: object|null,
     *   right: object|null,
     *   has_children: bool
     * }
     */
    public function binaryTreeChildren(Request $request, CareerRankService $careerRankService)
    {
        /** @var User $me */
        $me = $request->user();

        $parentId = (int) ($request->query('parent_id') ?? 0);
        if ($parentId <= 0) {
            $parentId = (int) $me->id;
        }

        // Seguridad: permitir solo el propio usuario o nodos dentro de su downline binario.
        if ($parentId !== (int) $me->id && ! $this->isInMyBinaryDownline($me->id, $parentId)) {
            return response()->json(['ok' => false, 'message' => 'Nodo fuera de tu red.'], 403);
        }

        $parent = User::query()->find($parentId);
        if (! $parent) {
            return response()->json(['ok' => false, 'message' => 'Usuario no encontrado.'], 404);
        }

        $leftPl = BinaryPlacement::query()
            ->where('parent_user_id', $parentId)
            ->where('leg_position', BinaryPlacement::LEG_LEFT)
            ->first();
        $rightPl = BinaryPlacement::query()
            ->where('parent_user_id', $parentId)
            ->where('leg_position', BinaryPlacement::LEG_RIGHT)
            ->first();

        $leftUser = $leftPl ? User::query()->find($leftPl->user_id) : null;
        $rightUser = $rightPl ? User::query()->find($rightPl->user_id) : null;

        $node = $this->binaryNodeDto($parent, $careerRankService);
        $node['left'] = $leftUser ? $this->binaryNodeDto($leftUser, $careerRankService) : null;
        $node['right'] = $rightUser ? $this->binaryNodeDto($rightUser, $careerRankService) : null;

        return response()->json(['ok' => true, 'node' => $node]);
    }

    /**
     * Buscar un usuario por nombre o código dentro de mi red binaria y devolver su nodo (con hijos left/right).
     *
     * Query params:
     * - q: string (nombre o código)
     */
    public function binaryTreeSearch(Request $request, CareerRankService $careerRankService)
    {
        /** @var User $me */
        $me = $request->user();

        $q = trim((string) $request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['ok' => false, 'message' => 'Escribe al menos 2 caracteres.'], 422);
        }

        // 1) Intento por código exacto (member_code o referral_code).
        $byCode = User::query()
            ->where(function ($w) use ($q) {
                $w->where('member_code', $q)->orWhere('referral_code', $q);
            })
            ->first();

        $candidate = null;
        if ($byCode) {
            $candidate = $byCode;
        } else {
            // 2) Búsqueda por nombre/código parcial; luego filtramos a mi downline.
            $candidates = User::query()
                ->where(function ($w) use ($q) {
                    $w->where('name', 'like', '%' . $q . '%')
                        ->orWhere('member_code', 'like', '%' . $q . '%')
                        ->orWhere('referral_code', 'like', '%' . $q . '%');
                })
                ->orderByRaw("case when account_status = 'active' then 0 when account_status = 'pending' then 1 else 2 end")
                ->limit(20)
                ->get();

            foreach ($candidates as $u) {
                if ((int) $u->id === (int) $me->id || $this->isInMyBinaryDownline((int) $me->id, (int) $u->id)) {
                    $candidate = $u;
                    break;
                }
            }
        }

        if (! $candidate) {
            return response()->json(['ok' => false, 'message' => 'No se encontró un usuario en tu red con ese dato.'], 404);
        }

        $id = (int) $candidate->id;
        if ($id !== (int) $me->id && ! $this->isInMyBinaryDownline((int) $me->id, $id)) {
            return response()->json(['ok' => false, 'message' => 'Usuario fuera de tu red.'], 403);
        }

        // Reutiliza la forma de binaryTreeChildren para que el front pueda renderizar con BinaryTreeBranch.
        $leftPl = BinaryPlacement::query()
            ->where('parent_user_id', $id)
            ->where('leg_position', BinaryPlacement::LEG_LEFT)
            ->first();
        $rightPl = BinaryPlacement::query()
            ->where('parent_user_id', $id)
            ->where('leg_position', BinaryPlacement::LEG_RIGHT)
            ->first();

        $leftUser = $leftPl ? User::query()->find($leftPl->user_id) : null;
        $rightUser = $rightPl ? User::query()->find($rightPl->user_id) : null;

        $node = $this->binaryNodeDto($candidate, $careerRankService);
        $node['left'] = $leftUser ? $this->binaryNodeDto($leftUser, $careerRankService) : null;
        $node['right'] = $rightUser ? $this->binaryNodeDto($rightUser, $careerRankService) : null;

        return response()->json([
            'ok' => true,
            'q' => $q,
            'match' => [
                'id' => (int) $candidate->id,
                'name' => (string) $candidate->name,
                'code' => (string) ($candidate->member_code ?? $candidate->referral_code ?? $candidate->id),
            ],
            'node' => $node,
        ]);
    }

    protected function binaryNodeDto(User $u, CareerRankService $careerRankService): array
    {
        $u->loadMissing('rank');
        $hasChildren = BinaryPlacement::query()->where('parent_user_id', $u->id)->exists();

        $u->loadMissing('registrationPackage', 'referrals.rank');
        $slug = $careerRankService->displayRankSlug($u);
        $dr = Rank::query()->where('slug', $slug)->first();
        $rankName = $dr?->name ?? $slug;

        return [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'code' => (string) ($u->member_code ?? $u->referral_code ?? $u->id),
            'phone' => (string) ($u->phone ?? ''),
            'email' => (string) ($u->email ?? ''),
            // Rango mostrado (persistido vs elegible): no depende solo del PV del mes.
            'rank' => (string) ($rankName ?: '—'),
            // PV acumulado efectivo para rangos/carrera (incluye periodo actual abierto).
            'pv_accumulated' => (string) $careerRankService->effectiveCareerPv($u),
            // “Activo” en red = calificación mensual (recompra / umbral MLM_MONTHLY_ACTIVATION_PV).
            'is_active' => (bool) $u->is_mlm_qualified,
            'left' => null,
            'right' => null,
            'has_children' => $hasChildren,
        ];
    }

    protected function isInMyBinaryDownline(int $meId, int $candidateId): bool
    {
        // Subir por parent_user_id en binary_placements hasta encontrarme (o cortar).
        $cursor = $candidateId;
        for ($i = 0; $i < 80; $i++) {
            $pl = BinaryPlacement::query()->where('user_id', $cursor)->first();
            if (! $pl || ! $pl->parent_user_id) {
                return false;
            }
            $cursor = (int) $pl->parent_user_id;
            if ($cursor === $meId) {
                return true;
            }
        }
        return false;
    }

    private function countBinaryDownlineUsers(int $parentUserId): int
    {
        $left = BinaryPlacement::query()
            ->where('parent_user_id', $parentUserId)
            ->where('leg_position', BinaryPlacement::LEG_LEFT)
            ->first();
        $right = BinaryPlacement::query()
            ->where('parent_user_id', $parentUserId)
            ->where('leg_position', BinaryPlacement::LEG_RIGHT)
            ->first();

        $n = 0;
        if ($left) {
            $n++;
            $n += $this->countBinaryDownlineUsers($left->user_id);
        }
        if ($right) {
            $n++;
            $n += $this->countBinaryDownlineUsers($right->user_id);
        }

        return $n;
    }

    private function binarySubtreePayload(?int $userId, int $levelsBelow): ?array
    {
        if (! $userId) {
            return null;
        }

        $u = User::query()->find($userId);
        if (! $u) {
            return null;
        }

        $node = [
            'user' => [
                'id' => $u->id,
                'name' => $u->name,
                'member_code' => $u->member_code,
                'initials' => $this->initialsFromName($u->name),
            ],
            'left' => null,
            'right' => null,
        ];

        if ($levelsBelow <= 0) {
            return $node;
        }

        $leftPl = BinaryPlacement::query()
            ->where('parent_user_id', $userId)
            ->where('leg_position', BinaryPlacement::LEG_LEFT)
            ->first();
        $rightPl = BinaryPlacement::query()
            ->where('parent_user_id', $userId)
            ->where('leg_position', BinaryPlacement::LEG_RIGHT)
            ->first();

        $node['left'] = $leftPl
            ? $this->binarySubtreePayload($leftPl->user_id, $levelsBelow - 1)
            : null;
        $node['right'] = $rightPl
            ? $this->binarySubtreePayload($rightPl->user_id, $levelsBelow - 1)
            : null;

        return $node;
    }

    private function initialsFromName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $a = $parts[0][0] ?? 'U';
        $b = isset($parts[1]) ? $parts[1][0] : '';

        return strtoupper($a.$b);
    }

    public function commissions(Request $request)
    {
        $userId = $request->user()->id;
        $bobPerPv = (string) config('mlm.pv_value.bob_per_pv', '7');

        $total = (string) CommissionEvent::query()
            ->where('beneficiary_user_id', $userId)
            ->sum('amount');

        $pendingPayout = (string) CommissionEvent::query()
            ->where('beneficiary_user_id', $userId)
            ->whereNull('wallet_credited_at')
            ->sum('amount');

        $creditedToWallet = (string) CommissionEvent::query()
            ->where('beneficiary_user_id', $userId)
            ->whereNotNull('wallet_credited_at')
            ->sum('amount');

        $birByLevel = [];
        for ($level = 1; $level <= 3; $level++) {
            $birByLevel[$level] = (string) CommissionEvent::query()
                ->where('beneficiary_user_id', $userId)
                ->where('type', 'bir')
                ->where('level', $level)
                ->sum('amount');
        }

        $items = CommissionEvent::query()
            ->where('beneficiary_user_id', $userId)
            ->with('commission')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function (CommissionEvent $e) {
                $bobPerPv = (string) config('mlm.pv_value.bob_per_pv', '7');
                $meta = is_array($e->meta) ? $e->meta : [];
                $pv = null;
                if (isset($meta['pv_comision_nivel'])) {
                    $pv = (string) $meta['pv_comision_nivel'];
                } elseif (isset($meta['pv_comision'])) {
                    $pv = (string) $meta['pv_comision'];
                } elseif (isset($meta['matched_pv'])) {
                    $pv = (string) $meta['matched_pv'];
                } elseif (bccomp((string) $bobPerPv, '0', 2) === 1) {
                    // PV aproximado si solo tenemos Bs (retrocompat).
                    $pv = bcdiv((string) $e->amount, (string) $bobPerPv, 4);
                }

                $rowCommission = $e->commission;
                $payoutPending = $e->wallet_credited_at === null;

                return [
                    'id' => $e->id,
                    'type' => $e->type,
                    'type_label' => match ($e->type) {
                        'bir' => 'Bono inicio rápido',
                        'binary' => 'Bono binario',
                        'residual' => 'Residual',
                        'leadership' => 'Liderazgo',
                        'venta_directa' => 'Bono venta directa',
                        default => strtoupper($e->type),
                    },
                    'amount_bob' => (string) $e->amount,
                    'pv_amount' => $pv,
                    'bob_per_pv' => $meta['bob_per_pv'] ?? $bobPerPv,
                    'currency' => $e->currency,
                    'level' => $e->level,
                    'period_key' => $e->period_key,
                    'accrual_week_key' => $e->accrual_week_key,
                    'created_at' => $e->created_at?->toIso8601String(),
                    'period_display' => $e->created_at ? $e->created_at->format('d/m/y') : null,
                    'wallet_credited_at' => $e->wallet_credited_at?->toIso8601String(),
                    'payout_pending' => $payoutPending,
                    'status' => $rowCommission?->status ?? 'accrued',
                ];
            });

        $binaryHybrid = null;
        if ((bool) config('mlm.binary.hybrid_daily.enabled', false)) {
            $days = [];
            for ($i = 0; $i < 14; $i++) {
                $days[] = now()->subDays($i)->toDateString();
            }

            $daily = BinaryDailyPayout::query()
                ->where('user_id', $userId)
                ->whereIn('day_key', $days)
                ->orderByDesc('day_key')
                ->get()
                ->map(fn (BinaryDailyPayout $p) => [
                    // day_key puede venir como string (Y-m-d) o Date; normalizamos a string.
                    'day_key' => $p->day_key instanceof \DateTimeInterface
                        ? $p->day_key->format('Y-m-d')
                        : (string) $p->day_key,
                    'matched_pv' => (string) $p->matched_pv,
                    'daily_bonus_bob' => (string) $p->daily_bonus_bob,
                    'rate' => (string) (($p->meta ?? [])['rate'] ?? config('mlm.binary.hybrid_daily.rate', '0.21')),
                    'bob_per_pv' => (string) (($p->meta ?? [])['bob_per_pv'] ?? $bobPerPv),
                ])
                ->values()
                ->all();

            $weekly = BinaryWeeklyBonus::query()
                ->where('user_id', $userId)
                ->orderByDesc('week_key')
                ->limit(8)
                ->get()
                ->map(fn (BinaryWeeklyBonus $w) => [
                    'week_key' => (string) $w->week_key,
                    'weekly_bonus_bob' => (string) $w->weekly_bonus_bob,
                    'paid_weekly_bonus_bob' => (string) $w->paid_weekly_bonus_bob,
                    'accumulated_unpaid_bob' => (string) $w->accumulated_unpaid_bob,
                    'final_accumulated_bob' => (string) $w->final_accumulated_bob,
                    'month_key' => (string) $w->month_key,
                    'month_penalty_applied' => (bool) $w->month_penalty_applied,
                    'meta' => $w->meta,
                ])
                ->values()
                ->all();

            $binaryHybrid = [
                'enabled' => true,
                'mode' => 'hybrid_daily',
                'days_window' => 14,
                'weeks_window' => 8,
                'daily' => $daily,
                'weekly' => $weekly,
                'config' => [
                    'rate' => (string) config('mlm.binary.hybrid_daily.rate', '0.21'),
                    'weekly_cap_usd' => (string) config('mlm.binary.hybrid_daily.weekly_cap_usd', '2500'),
                    'weekly_cap_bob_override' => (string) config('mlm.binary.hybrid_daily.weekly_cap_bob', ''),
                    'month_penalty' => (string) config('mlm.binary.hybrid_daily.month_penalty', '0.10'),
                    'bob_per_usd' => (string) config('mlm.auto_okm.bob_per_usd', '7'),
                    'bob_per_pv' => (string) $bobPerPv,
                ],
            ];
        }

        return response()->json([
            'summary' => [
                'total_accrued' => $total,
                'pending_payout_bob' => $pendingPayout,
                'credited_to_wallet_bob' => $creditedToWallet,
            ],
            'exchange' => [
                'label' => 'Tipo de cambio interno',
                'note' => '1 PV = 7 Bs',
                'bob_per_pv' => $bobPerPv,
            ],
            'bir_by_level' => $birByLevel,
            'bir_percentages' => config('mlm.bir.schedule', []),
            'items' => $items,
            'binary_hybrid' => $binaryHybrid,
        ]);
    }

    /**
     * Notificaciones para el panel: comisiones recientes, pedidos completados y avisos de rango.
     */
    public function notifications(Request $request, CareerRankService $careerRankService)
    {
        $uid = (int) $request->user()->id;
        $items = [];

        $u = $request->user()->loadMissing('rank');
        $meta = (array) ($u->meta ?? []);
        $dismissedIds = array_values(array_filter(array_map('strval', (array) ($meta['dismissed_notification_ids'] ?? []))));
        $clearedAtRaw = $meta['notifications_cleared_at'] ?? null;
        $clearedAt = null;
        try {
            if ($clearedAtRaw) {
                $clearedAt = \Carbon\Carbon::parse((string) $clearedAtRaw);
            }
        } catch (\Throwable $e) {
            $clearedAt = null;
        }

        if ($u->rank?->name) {
            $items[] = [
                'id' => 'rank-current',
                'type' => 'rank',
                'title' => 'Tu rango',
                'body' => 'Rango en tu perfil: '.$u->rank->name,
                'created_at' => optional($u->updated_at)?->toIso8601String(),
                'url' => '/cuenta',
            ];
        }

        $u->loadMissing('rank', 'registrationPackage', 'referrals.rank');
        $computedSlug = $careerRankService->computeHighestEligibleRankSlug($u);
        $computedRank = Rank::query()->where('slug', $computedSlug)->first();
        $storedRank = $u->rank;
        if ($computedRank && $storedRank && (int) $computedRank->sort_order > (int) $storedRank->sort_order) {
            $eligibleName = $computedRank->name ?? $computedSlug;
            $items[] = [
                'id' => 'rank-eligible-'.substr(md5((string) $computedSlug), 0, 12),
                'type' => 'rank',
                'title' => 'Calificación de rango',
                'body' => 'Por volumen y equipo podrías ascender a: '.$eligibleName.'. Revisa requisitos en Perfil.',
                'created_at' => now()->toIso8601String(),
                'url' => '/profile',
            ];
        }

        $events = CommissionEvent::query()
            ->where('beneficiary_user_id', $uid)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        foreach ($events as $ev) {
            $items[] = [
                'id' => 'commission-'.$ev->id,
                'type' => 'commission',
                'subtype' => $ev->type,
                'title' => $this->notificationTitleForCommissionType((string) $ev->type),
                'body' => 'Acreditación de '.number_format((float) $ev->amount, 2, ',', '.').' '.($ev->currency ?? 'BOB')
                    .($ev->period_key ? ' · Periodo '.$ev->period_key : ''),
                'created_at' => $ev->created_at?->toIso8601String(),
                'url' => '/comisiones',
                'meta' => $ev->meta,
            ];
        }

        $orders = Order::query()
            ->where('user_id', $uid)
            ->where('estado', 'completado')
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get(['id', 'tipo', 'total', 'completed_at']);

        foreach ($orders as $o) {
            $items[] = [
                'id' => 'order-'.$o->id,
                'type' => 'order',
                'title' => 'Pedido completado',
                'body' => 'Tipo '.($o->tipo ?? '').' · Total '.number_format((float) $o->total, 2, ',', '.').' BOB',
                'created_at' => $o->completed_at?->toIso8601String(),
                'url' => '/compras-realizadas',
            ];
        }

        usort($items, function ($a, $b) {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        // Filtrar notificaciones descartadas por el usuario (por id o por "limpiar todo").
        $items = array_values(array_filter($items, function ($n) use ($dismissedIds, $clearedAt) {
            $id = (string) ($n['id'] ?? '');
            if ($id !== '' && in_array($id, $dismissedIds, true)) {
                return false;
            }
            if ($clearedAt) {
                $created = (string) ($n['created_at'] ?? '');
                if ($created !== '') {
                    try {
                        $ts = \Carbon\Carbon::parse($created);
                        if ($ts->lessThanOrEqualTo($clearedAt)) {
                            return false;
                        }
                    } catch (\Throwable $e) {
                        // si no parsea, no filtramos por fecha
                    }
                }
            }
            return true;
        }));

        return response()->json([
            'ok' => true,
            'items' => array_slice($items, 0, 40),
        ]);
    }

    /**
     * Descartar notificaciones del panel.
     *
     * - Para limpiar todo: { all: true } (o query all=1)
     * - Para descartar por id: { ids: ["commission-123", "order-55"] }
     */
    public function dismissNotifications(Request $request)
    {
        /** @var User $u */
        $u = $request->user();
        $meta = (array) ($u->meta ?? []);

        $all = (bool) ($request->boolean('all') || $request->input('all') === true);
        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('strval', $ids)));

        if ($all || empty($ids)) {
            $meta['notifications_cleared_at'] = now()->toIso8601String();
            $meta['dismissed_notification_ids'] = [];
        } else {
            $existing = array_values(array_filter(array_map('strval', (array) ($meta['dismissed_notification_ids'] ?? []))));
            $set = array_values(array_unique(array_merge($existing, $ids)));
            $meta['dismissed_notification_ids'] = $set;
        }

        $u->forceFill(['meta' => $meta])->save();

        return response()->json(['ok' => true]);
    }

    private function notificationTitleForCommissionType(string $type): string
    {
        return match ($type) {
            'binary' => 'Comisión binaria',
            'bir' => 'Bono inicio rápido',
            'residual' => 'Comisión residual',
            'venta_directa' => 'Venta directa',
            'leadership' => 'Bono liderazgo',
            default => 'Comisión',
        };
    }

    public function founder(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        // Misma fuente que el dashboard (MlmBonusProgressService::plataOnboardingPayload):
        // PV comisionables de pedidos completados en los últimos 90 días.
        $since90 = Carbon::now()->subDays(90);
        $pvCredited = Order::sumCommissionablePvForUserBetween((int) $user->id, $since90, Carbon::now());
        $pvRegistrationPending = '0';
        $pvTotal = bcadd($pvCredited, $pvRegistrationPending, 2);
        $completed = (bool) ($user->paquete_fundador_completado ?? false);
        $target = bcadd((string) config('mlm.onboarding.plata_pv_required', '1200'), '0', 2);

        if (! $completed && bccomp($pvTotal, $target, 2) >= 0) {
            $completed = true;
            $user->forceFill(['paquete_fundador_completado' => true])->save();
        }

        return response()->json([
            'ok' => true,
            'pv_actual' => (string) $pvTotal,
            'pv_actual_credited' => (string) $pvCredited,
            'pv_registration_pending' => (string) $pvRegistrationPending,
            'pv_actual_total' => (string) $pvTotal,
            'pv_90d' => (string) $pvCredited,
            'target_pv' => $target,
            'paquete_fundador_completado' => $completed,
        ]);
    }

    /**
     * @return array{id: ?int, name: string, slug: string}
     */
    protected function displayRankTuple(User $user, CareerRankService $careerRankService): array
    {
        $user->loadMissing('rank', 'registrationPackage', 'referrals.rank');
        $slug = $careerRankService->displayRankSlug($user);
        $rank = Rank::query()->where('slug', $slug)->first();

        return [
            'id' => $rank?->id,
            'name' => (string) ($rank?->name ?? $slug),
            'slug' => $slug,
        ];
    }

    public function founderPurchase(Request $request)
    {
        $data = $request->validate([
            'package' => 'required|string|in:basico,avanzado,profesional,fundador',
        ]);

        /** @var User $user */
        $user = $request->user()->fresh();
        $pv = FounderPackages::pv($data['package']);

        $current = bcadd((string) ($user->pv_actual ?? '0'), '0', 2);
        $new = bcadd($current, $pv, 2);
        $completed = bccomp($new, '1200', 2) >= 0;

        $user->forceFill([
            'pv_actual' => $new,
            'paquete_fundador_completado' => $completed ? true : (bool) ($user->paquete_fundador_completado ?? false),
        ])->save();

        return response()->json([
            'ok' => true,
            'package' => $data['package'],
            'pv_added' => $pv,
            'pv_actual' => (string) $new,
            'target_pv' => '1200',
            'paquete_fundador_completado' => (bool) $user->paquete_fundador_completado,
        ]);
    }
}
