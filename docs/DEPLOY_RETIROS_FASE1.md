# Despliegue — Retiros seguros Fase 1

Si el front llama `https://api.tbnliving.com/api/v1/wallet/withdraw/*` y recibe **404**, el API en producción **no tiene** este código o la caché de rutas está desactualizada.

## Checklist en el servidor API

```bash
cd /ruta/al/test-back-synkai-main

git pull   # o subir todos los archivos nuevos

composer install --no-dev --optimize-autoloader

php artisan migrate --force

php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan optimize

php artisan route:list --path=wallet/withdraw
```

Debes ver rutas como:

- `GET api/v1/wallet/withdraw/config`
- `POST api/v1/wallet/withdraw/request`
- `POST api/v1/wallet/withdraw/verify-otp`

## Si `/api/v1/auth/me` devuelve 500

1. Revisar `storage/logs/laravel.log` en el servidor.
2. Causas frecuentes:
   - Despliegue **incompleto** (faltan clases en `app/Events`, `app/Services/Wallet`, etc.).
   - Migración **no ejecutada** (`withdrawal_otps`, columnas en `withdrawals`).
   - `composer dump-autoload` no corrido tras subir archivos.

## Archivos críticos del módulo

- `routes/definitions/member_api_routes.php`
- `app/Http/Controllers/Api/WalletWithdrawController.php`
- `app/Services/Wallet/SecureWithdrawalService.php`
- `database/migrations/2026_05_24_120000_withdrawal_security_phase1.php`
