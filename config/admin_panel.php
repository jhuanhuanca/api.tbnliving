<?php

/**
 * Mapeo de roles MLM del core → roles/permisos esperados por el panel Vue admin.
 */
return [
    'role_labels' => [
        'superadmin' => 'Super Admin',
        'admin' => 'Financial Admin',
        'support' => 'Support Admin',
    ],

    'permissions_by_role' => [
        'superadmin' => ['*'],
        'admin' => [
            'dashboard.view',
            'users.view',
            'users.manage',
            'commissions.view',
            'wallet.view',
            'withdrawals.view',
            'withdrawals.approve',
            'orders.view',
            'orders.confirm',
            'products.view',
            'products.manage',
            'packages.view',
            'packages.manage',
            'tree.view',
            'reports.view',
            'analytics.view',
            'settings.view',
            'support.tickets',
        ],
        'support' => [
            'dashboard.view',
            'users.view',
            'withdrawals.view',
            'orders.view',
            'tree.view',
            'support.tickets',
        ],
    ],

    'default_country_code' => env('ADMIN_PANEL_DEFAULT_COUNTRY', 'BO'),
];
