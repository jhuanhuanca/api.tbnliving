<?php

return [

    /**
     * Valores en columna users.mlm_role.
     * admin: panel empresa; support: opcional mismo acceso que admin según rutas; member: socio.
     */
    'roles' => [
        'member' => 'member',
        'admin' => 'admin',
        'superadmin' => 'superadmin',
        'support' => 'support',
    ],

    /** Roles con acceso a /api/admin/* (variable de entorno separada por comas). */
    'admin_roles' => array_filter(array_map('trim', explode(',', (string) env('MLM_ADMIN_ROLES', 'admin,superadmin,support')))),

    'currency' => env('MLM_CURRENCY', 'BOB'),

    /**
     * BIR, residual, venta directa y liderazgo: se registran al generarse;
     * el abono en billetera se ejecuta en el job semanal (domingo) por semana ISO de devengo.
     * El binario sigue acreditándose en el cierre binario (inmediato respecto a ese job).
     */
    'commissions' => [
        'defer_non_binary_to_weekly_close' => filter_var(env('MLM_DEFER_COMMISSIONS_TO_WEEKLY_CLOSE', true), FILTER_VALIDATE_BOOL),
    ],

    /** Si true, la re-evaluación mensual puede bajar de rango cuando el PV no cumple umbrales. Por defecto false: el rango alcanzado se conserva; la recompra mensual controla comisiones vía is_mlm_qualified. */
    'rank' => [
        'allow_downgrade_on_monthly_eval' => filter_var(env('MLM_RANK_ALLOW_DOWNGRADE', false), FILTER_VALIDATE_BOOL),
    ],

    /** Código numérico consecutivo del socio (también es referral_code en string). */
    'member_code' => [
        'min' => (int) env('MLM_MEMBER_CODE_MIN', 1),
        'max' => (int) env('MLM_MEMBER_CODE_MAX', 1_000_000),
        /** Ancho con ceros a la izquierda en referral_code (000001 … 1000000). */
        'pad_width' => (int) env('MLM_MEMBER_CODE_PAD_WIDTH', 6),
    ],

    /** Espacios reservados bajo el MASTER en cada pierna binaria (seeder). */
    'master_network' => [
        'reserved_per_leg' => (int) env('MLM_MASTER_RESERVED_PER_LEG', 18),
    ],

    /**
     * @deprecated No usar en lógica nueva. Umbrales reales en mlm.career.direct_active_min_pv (50)
     *             y mlm.career.monthly_activation.min_pv_diamond_plus (100).
     */
    'monthly_activation_pv' => (float) env('MLM_MONTHLY_ACTIVATION_PV', 50),

    /**
     * Ciclo de calificación comercial (día 1 al 27 del mes; el resto puede ser cierre/admin).
     */
    'qualification_cycle' => [
        'start_day' => (int) env('MLM_CYCLE_START_DAY', 1),
        'end_day' => (int) env('MLM_CYCLE_END_DAY', 27),
    ],

    /**
     * Valor monetario de 1 PV en moneda local (p. ej. BOB por PV).
     * Usado en BIR (21%/15%/6% sobre PV del paquete) y en binario (21% sobre PV emparejado).
     */
    'pv_value' => [
        // Tipo de cambio oficial interno: 1 PV = 7 Bs.
        'bob_per_pv' => (string) env('MLM_BOB_PER_PV', '7'),
    ],

    /**
     * Bono inicio rápido (BIR): % sobre PV del ítem de paquete, por generación de patrocinio.
     * Importe en moneda = (PV_línea × tasa_nivel) × bob_per_pv.
     */
    'bir' => [
        'base' => env('MLM_BIR_BASE', 'pv'), // pv | commissionable_amount
        'schedule' => [
            1 => 0.21,
            2 => 0.15,
            3 => 0.06,
        ],
        'period' => 'weekly',
    ],

    /**
     * Binario: sobre el volumen emparejado (pierna débil).
     * - weekly: semana ISO (clave o-\WW); carry a la siguiente semana.
     * - monthly: mes calendario (clave Y-m); carry al mes siguiente (21 % sobre PV emparejado del mes).
     */
    'binary' => [
        'volume_period' => env('MLM_BINARY_VOLUME_PERIOD', 'monthly'),
        'legacy_flat' => filter_var(env('MLM_BINARY_LEGACY_FLAT', false), FILTER_VALIDATE_BOOL),
        'payout_per_matched_pv' => (string) env('MLM_BINARY_PAYOUT_PER_PV', '1'),
        'bob_per_pv' => (string) env('MLM_BINARY_BOB_PER_PV', env('MLM_BOB_PER_PV', '7')),
        'matched_pv_commission_rate' => (string) env('MLM_BINARY_MATCHED_RATE', '0.21'),
        'cache_ttl_seconds' => (int) env('MLM_BINARY_CACHE_TTL', 600),
        'cache_prefix' => 'mlm:binary:',
        /**
         * Modo híbrido diario (B): bono diario, cap semanal y penalización mensual del acumulado no pagado.
         * - Se calcula daily_bonus = matchedPV(día) × rate
         * - Se paga semanalmente con cap (BOB)
         * - Lo no pagado recibe penalización mensual (porcentaje)
         */
        'hybrid_daily' => [
            'enabled' => filter_var(env('MLM_BINARY_HYBRID_DAILY', true), FILTER_VALIDATE_BOOL),
            'rate' => (string) env('MLM_BINARY_HYBRID_RATE', '0.21'),
            /**
             * Tope semanal del pago binario.
             * Si defines weekly_cap_usd, el sistema lo convierte a BOB usando mlm.auto_okm.bob_per_usd (por defecto 6.96; recomendado 7).
             * Puedes sobreescribir directamente en BOB con weekly_cap_bob.
             */
            'weekly_cap_usd' => (string) env('MLM_BINARY_HYBRID_WEEKLY_CAP_USD', '2500'),
            'weekly_cap_bob' => (string) env('MLM_BINARY_HYBRID_WEEKLY_CAP_BOB', ''),
            'month_penalty' => (string) env('MLM_BINARY_HYBRID_MONTH_PENALTY', '0.10'),
        ],
        /** Tope de pago binario acumulado por periodo (solo aplica a eventos con meta.cap_period_key). */
        'cap' => [
            'enabled' => filter_var(env('MLM_BINARY_CAP_ENABLED', false), FILTER_VALIDATE_BOOL),
            'mode' => env('MLM_BINARY_CAP_MODE', 'weekly'),
            'max_bob' => (string) env('MLM_BINARY_CAP_MAX_BOB', '50000'),
        ],
    ],

    /**
     * Residual unilevel: porcentajes por generación según el rango efectivo del beneficiario.
     * effective_rank = mayor rango cuyo umbral de PV de grupo (estimado) cumple el patrocinador (ver rank_thresholds_pv + CareerRankService::groupQualifyingPvLight).
     * Si no hay matriz para el slug, se usa default_generations.
     */
    'residual' => [
        'period' => 'monthly',
        'base' => 'order_pv',
        /**
         * Nueva base calificadora de rangos para residual (solo para elegir slug/tabla de rates):
         * - true: usa CareerRankService::rankAccumulatedPv() para determinar el slug efectivo
         * - false: mantiene groupQualifyingPvLight()
         */
        'use_rank_accumulated_pv' => (bool) env('MLM_RESIDUAL_USE_RANK_ACC_PV', true),
        'default_generations' => [
            1 => 0.05,
            2 => 0.04,
            3 => 0.03,
            4 => 0.02,
            5 => 0.015,
            6 => 0.01,
            7 => 0.008,
            8 => 0.006,
            9 => 0.005,
            10 => 0.004,
            11 => 0.003,
            12 => 0.002,
        ],
        'matrix_by_rank_slug' => [
            'plata' => [1 => 0.12, 2 => 0.09, 3 => 0.09],
            'oro' => [1 => 0.12, 2 => 0.09, 3 => 0.09],
            'zafiro' => [1 => 0.12, 2 => 0.09, 3 => 0.09],
            'esmeralda' => [1 => 0.12, 2 => 0.09, 3 => 0.09, 4 => 0.06],
            'rubi' => [1 => 0.12, 2 => 0.09, 3 => 0.09, 4 => 0.06, 5 => 0.03],
            'diamante' => [1 => 0.12, 2 => 0.09, 3 => 0.09, 4 => 0.06, 5 => 0.03, 6 => 0.03],
            'diamante_ejecutivo' => [1 => 0.12, 2 => 0.09, 3 => 0.09, 4 => 0.06, 5 => 0.03, 6 => 0.03, 7 => 0.03],
            'doble_diamante' => [1 => 0.12, 2 => 0.09, 3 => 0.09, 4 => 0.06, 5 => 0.03, 6 => 0.03, 7 => 0.03, 8 => 0.03],
            'triple_diamante' => [1 => 0.12, 2 => 0.09, 3 => 0.09, 4 => 0.06, 5 => 0.03, 6 => 0.03, 7 => 0.03, 8 => 0.03, 9 => 0.03],
            'diamante_corona' => [1 => 0.12, 2 => 0.09, 3 => 0.09, 4 => 0.06, 5 => 0.03, 6 => 0.03, 7 => 0.03, 8 => 0.03, 9 => 0.03, 10 => 0.03],
            'doble_diamante_corona' => [1 => 0.12, 2 => 0.09, 3 => 0.09, 4 => 0.06, 5 => 0.03, 6 => 0.03, 7 => 0.03, 8 => 0.03, 9 => 0.03, 10 => 0.03, 11 => 0.03],
            'triple_diamante_corona' => [1 => 0.12, 2 => 0.09, 3 => 0.09, 4 => 0.06, 5 => 0.03, 6 => 0.03, 7 => 0.03, 8 => 0.03, 9 => 0.03, 10 => 0.03, 11 => 0.03, 12 => 0.03],
        ],
        /**
         * PV mínimos de grupo (misma magnitud que career.requirements.*.min_group_pv_light) para matriz residual.
         * Orden interno irrelevante; RankService ordena por valor.
         */
        'rank_thresholds_pv' => [
            'triple_diamante_corona' => 3_000_000,
            'doble_diamante_corona' => 2_000_000,
            'diamante_corona' => 1_000_000,
            'triple_diamante' => 360_000,
            'doble_diamante' => 240_000,
            'diamante_ejecutivo' => 180_000,
            'diamante' => 120_000,
            'esmeralda' => 60_000,
            'rubi' => 30_000,
            'zafiro' => 15_000,
            'oro' => 7200,
            'plata' => 3600,
        ],
    ],

    /**
     * Paquetes de ingreso (solo referencia / copy; el catálogo real está en `packages`).
     */
    'package_entry_pv' => [
        'basico' => 100,
        'avanzado' => 300,
        'profesional' => 600,
        'fundador' => 1200,
    ],

    /**
     * Carrera MLM post-fundador: evaluación en CareerRankService (PV grupo ligero, frontales, personal, rangos en directos).
     */
    'career' => [
        'fundador_min_package_pv' => 1200,
        /**
         * Activación mensual personal (is_mlm_qualified):
         * - Socios con rango < diamante_ejecutivo: PV mensual ≥ direct_active_min_pv (50).
         * - diamante_ejecutivo y superiores: PV mensual ≥ min_pv_diamond_plus (100).
         * Cuentan: paquetes de inicio, recompras fundador (siempre) y productos del catálogo
         * cuando una sola compra de productos alcanza el umbral de activación del socio.
         */
        'direct_active_min_pv' => 50,
        'monthly_activation' => [
            'min_pv_diamond_plus' => 100,
            'diamond_plus_from_rank_slug' => 'diamante_ejecutivo',
        ],
        /**
         * Nuevo PV para carrera de rango (separado):
         * - Solo PV acumulado de socios DIRECTOS válidos
         * - Con ponderación (ej: 60%)
         *
         * Esto NO cambia PV transaccional/bonos; solo afecta la métrica de carrera si se habilita.
         */
        'use_rank_accumulated_pv' => (bool) env('MLM_CAREER_USE_RANK_ACC_PV', true),
        'rank_accumulated_direct_factor' => (string) env('MLM_CAREER_RANK_ACC_DIRECT_FACTOR', '0.60'),
        /**
         * Orden de ascenso (menor → mayor). Debe coincidir con slugs en `ranks` (MlmBootstrapSeeder).
         */
        'rank_eval_order' => [
            'plata',
            'oro',
            'zafiro',
            'rubi',
            'esmeralda',
            'diamante',
            'diamante_ejecutivo',
            'doble_diamante',
            'triple_diamante',
            'diamante_corona',
            'doble_diamante_corona',
            'triple_diamante_corona',
        ],
        /**
         * min_group_pv_light = PV propio del mes + PV del mes de patrocinados directos (aprox. de grupo).
         * min_personal_pv = activación personal mensual en PV del propio usuario.
         * min_directs_with_rank: en línea directa (sponsor_id = usuario), cuántos con rango ≥ slug.
         */
        'requirements' => [
            'plata' => [
                'min_group_pv_light' => 3600,
                'min_direct_actives' => 2,
                'min_personal_pv' => 50,
                'min_directs_with_rank' => [],
            ],
            'oro' => [
                'min_group_pv_light' => 7200,
                'min_direct_actives' => 2,
                'min_personal_pv' => 50,
                'min_directs_with_rank' => [],
            ],
            'zafiro' => [
                'min_group_pv_light' => 15_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 50,
                'min_directs_with_rank' => [],
            ],
            'rubi' => [
                'min_group_pv_light' => 30_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 50,
                'min_directs_with_rank' => [],
            ],
            'esmeralda' => [
                'min_group_pv_light' => 60_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 50,
                'min_directs_with_rank' => [],
            ],
            'diamante' => [
                'min_group_pv_light' => 120_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 50,
                'min_directs_with_rank' => [],
            ],
            'diamante_ejecutivo' => [
                'min_group_pv_light' => 180_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 100,
                'min_directs_with_rank' => [
                    ['slug' => 'diamante', 'min_count' => 1],
                ],
            ],
            'doble_diamante' => [
                'min_group_pv_light' => 240_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 100,
                'min_directs_with_rank' => [
                    ['slug' => 'diamante', 'min_count' => 2],
                ],
            ],
            'triple_diamante' => [
                'min_group_pv_light' => 360_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 100,
                'min_directs_with_rank' => [
                    ['slug' => 'diamante', 'min_count' => 3],
                ],
            ],
            'diamante_corona' => [
                'min_group_pv_light' => 1_000_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 100,
                'min_directs_with_rank' => [
                    ['slug' => 'triple_diamante', 'min_count' => 1],
                ],
            ],
            'doble_diamante_corona' => [
                'min_group_pv_light' => 2_000_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 100,
                'min_directs_with_rank' => [
                    ['slug' => 'triple_diamante', 'min_count' => 2],
                ],
            ],
            'triple_diamante_corona' => [
                'min_group_pv_light' => 3_000_000,
                'min_direct_actives' => 2,
                'min_personal_pv' => 100,
                'min_directs_with_rank' => [
                    ['slug' => 'triple_diamante', 'min_count' => 3],
                ],
            ],
        ],
    ],

    /**
     * Onboarding: meta PV hacia Plata (ventana de meses).
     */
    'onboarding' => [
        'plata_pv_required' => (float) env('MLM_ONBOARDING_PLATA_PV', 3600),
        'plata_months_window' => (int) env('MLM_ONBOARDING_PLATA_MONTHS', 3),
    ],

    /**
     * Socios sin movimiento MLM en 365 días: eliminación o baja (ver comando mlm:purge-inactive-members).
     */
    'inactive_member' => [
        'days_without_activity' => (int) env('MLM_INACTIVE_PURGE_DAYS', 365),
    ],

    /**
     * Bono Auto OKM: meta de ganancias acumuladas (comisiones) en USD equivalente.
     */
    'auto_okm' => [
        'target_total_earnings_usd' => (float) env('MLM_AUTO_OKM_TARGET_USD', 90000),
        /** Cuántos BOB equivalen a 1 USD (para convertir comisiones en BOB → USD). */
        'bob_per_usd' => (string) env('MLM_BOB_PER_USD', '6.96'),
    ],

    /** Bono liderazgo: % sobre PV de calificación cuando aplica racha (ver LeadershipStreakService). */
    'leadership' => [
        'default_rate' => 0.10,
        'consecutive_months_required' => 3,
        /**
         * Nueva base calificadora para liderazgo:
         * - true: usa CareerRankService::rankAccumulatedPv() (directos * factor)
         * - false: mantiene comportamiento legacy basado en snapshots (teamPvLight)
         */
        'use_rank_accumulated_pv' => (bool) env('MLM_LEADERSHIP_USE_RANK_ACC_PV', true),
        /**
         * PV requerido por mes (equipo) para habilitar liderazgo, por slug de rango.
         * Si no está definido, se intenta usar `mlm.career.requirements.<slug>.min_group_pv_light`.
         */
        'required_pv_by_rank_slug' => [
            // ejemplo:
            // 'plata' => 3600,
        ],
    ],

    'queues' => [
        'binary' => env('MLM_QUEUE_BINARY', 'default'),
        'residual' => env('MLM_QUEUE_RESIDUAL', 'default'),
        'withdrawals' => env('MLM_QUEUE_WITHDRAWALS', 'default'),
        'mail' => env('MLM_QUEUE_MAIL', 'default'),
        'high' => env('MLM_QUEUE_HIGH', 'high'),
        'low' => env('MLM_QUEUE_LOW', 'low'),
    ],

    /**
     * Retiros de billetera (Fase 1: contraseña + OTP email).
     * Futuro: google2fa_secret en users + middleware 2FA.
     */
    'withdrawals' => [
        'currency' => env('MLM_WITHDRAWAL_CURRENCY', 'BOB'),
        'min_amount' => (string) env('MLM_WITHDRAWAL_MIN', '1.00'),
        'max_amount' => (string) env('MLM_WITHDRAWAL_MAX', '50000.00'),
        'fee_percent' => (float) env('MLM_WITHDRAWAL_FEE_PERCENT', 0),
        'fee_fixed' => (string) env('MLM_WITHDRAWAL_FEE_FIXED', '0.00'),
        'otp_ttl_minutes' => (int) env('MLM_WITHDRAWAL_OTP_TTL', 5),
        'otp_max_attempts' => (int) env('MLM_WITHDRAWAL_OTP_MAX_ATTEMPTS', 3),
        'otp_resend_cooldown_seconds' => (int) env('MLM_WITHDRAWAL_OTP_COOLDOWN', 60),
    ],

    /** Facturación automática al completar pedido (InvoiceService). */
    'invoice' => [
        'default_tax_rate' => (string) env('MLM_INVOICE_TAX_PCT', '0'),
        'issuer_nit' => env('MLM_INVOICE_ISSUER_NIT'),
        'issuer_name' => env('MLM_INVOICE_ISSUER_NAME'),
        'authorization_code' => env('MLM_INVOICE_AUTH_CODE'),
        'email' => [
            'send_order_invoice' => filter_var(env('MLM_INVOICE_EMAIL_ORDER', true), FILTER_VALIDATE_BOOL),
            'send_withdrawal_receipt' => filter_var(env('MLM_INVOICE_EMAIL_WITHDRAWAL', true), FILTER_VALIDATE_BOOL),
        ],
        'electronic' => [
            'enabled' => (bool) env('MLM_INVOICE_ELECTRONIC_ENABLED', false),
            'api_url' => env('MLM_INVOICE_ELECTRONIC_URL', ''),
            'api_token' => env('MLM_INVOICE_ELECTRONIC_TOKEN', ''),
            'timeout_seconds' => (int) env('MLM_INVOICE_ELECTRONIC_TIMEOUT', 30),
        ],
    ],

    /** Correos transaccionales a socios (bienvenida, compras, tickets, rangos). */
    'notifications' => [
        'email' => [
            'enabled' => filter_var(env('MLM_EMAIL_NOTIFICATIONS', true), FILTER_VALIDATE_BOOL),
            'welcome_on_register' => filter_var(env('MLM_EMAIL_WELCOME', true), FILTER_VALIDATE_BOOL),
            'sponsor_on_referral' => filter_var(env('MLM_EMAIL_SPONSOR_REFERRAL', true), FILTER_VALIDATE_BOOL),
            'order_purchase' => filter_var(env('MLM_EMAIL_ORDER_PURCHASE', true), FILTER_VALIDATE_BOOL),
            'support_ticket_status' => filter_var(env('MLM_EMAIL_TICKET_STATUS', true), FILTER_VALIDATE_BOOL),
            'rank_achievement' => filter_var(env('MLM_EMAIL_RANK_ACHIEVEMENT', true), FILTER_VALIDATE_BOOL),
        ],
    ],
];
