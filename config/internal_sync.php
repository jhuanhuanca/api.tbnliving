<?php

return [
    /**
     * API interna read-only para sincronización CQRS (core -> panel).
     * Seguridad:
     * - Token por header: X-Internal-Token
     * - (Opcional) allowlist IPs
     */
    'token' => env('INTERNAL_SYNC_TOKEN', ''),

    // CSV: "1.2.3.4,5.6.7.8"
    'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('INTERNAL_SYNC_IP_ALLOWLIST', ''))))),

    'rate_limit_per_minute' => (int) env('INTERNAL_SYNC_RATE_LIMIT_PER_MINUTE', 240),

    /** Usuario MLM usado en acciones POST del panel (confirmar pago, aprobar retiros). */
    'panel_system_user_id' => (int) env('INTERNAL_PANEL_SYSTEM_USER_ID', 1),
];

