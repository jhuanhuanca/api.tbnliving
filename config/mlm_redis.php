<?php

/**
 * Redis / cache MLM — TTLs, locks y colas nombradas.
 * Activar con CACHE_STORE=redis, QUEUE_CONNECTION=redis, SESSION_DRIVER=redis.
 */
return [

    'enabled' => filter_var(env('MLM_REDIS_ENABLED', true), FILTER_VALIDATE_BOOL),

    'ttl' => [
        'admin_dashboard' => (int) env('MLM_CACHE_TTL_ADMIN_DASHBOARD', 120),
        'admin_analytics' => (int) env('MLM_CACHE_TTL_ADMIN_ANALYTICS', 180),
        'wallet_balance' => (int) env('MLM_CACHE_TTL_WALLET_BALANCE', 30),
        'binary_ancestors' => (int) env('MLM_BINARY_CACHE_TTL', 600),
        'rank_sort' => (int) env('MLM_CACHE_TTL_RANK_SORT', 3600),
        'tree_node' => (int) env('MLM_CACHE_TTL_TREE_NODE', 300),
        'leaderboard' => (int) env('MLM_CACHE_TTL_LEADERBOARD', 120),
    ],

    'locks' => [
        'wallet_seconds' => (int) env('MLM_LOCK_WALLET_SECONDS', 15),
        'withdrawal_seconds' => (int) env('MLM_LOCK_WITHDRAWAL_SECONDS', 30),
        'order_payment_seconds' => (int) env('MLM_LOCK_ORDER_PAYMENT_SECONDS', 20),
    ],

    /**
     * Colas Redis para workers Supervisor (orden de prioridad).
     */
    'queues' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MLM_REDIS_QUEUE_LIST',
        'high,default,mail,binary,residual,withdrawals,low'
    ))))),

    'horizon' => [
        'enabled' => filter_var(env('MLM_HORIZON_ENABLED', false), FILTER_VALIDATE_BOOL),
        'path' => env('MLM_HORIZON_PATH', 'horizon'),
    ],
];
