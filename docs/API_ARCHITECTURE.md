# Arquitectura API TBN Living (Synkai Core)

## Dominios

| Dominio | Rol |
|---------|-----|
| `api.tbnliving.com` | API REST Laravel (`test-back-synkai-main`) |
| `admin.tbnliving.com` | Panel Vue (`panel/`) |
| `front.tbnliving.com` | App miembros (`test-front_synkai-main`) |

## Versionado

| Capa | Base | Estado |
|------|------|--------|
| Legacy | `/api/login`, `/api/me/*`, `/api/admin/*` (miembros embebidos) | Mantenido sin cambios |
| **v1 (objetivo)** | `/api/v1/*` | Panel + app miembros + analytics |

Definición de rutas miembro: `routes/definitions/member_api_routes.php` (registrada en legacy y en v1).

## Autenticación panel SPA (`admin.tbnliving.com`)

1. `GET /sanctum/csrf-cookie` — con `credentials: include` (cookie `XSRF-TOKEN` en `.tbnliving.com`)
2. `POST /api/v1/admin/auth/login` — header `X-XSRF-TOKEN` + cookies de sesión
3. Respuesta: `access_token` (opcional en localStorage) + sesión `web`
4. `GET /api/v1/admin/auth/me` — validar sesión al arrancar (cookie y/o Bearer)
5. `POST /api/v1/admin/auth/logout` — invalida sesión y revoca token

Variables panel:

```env
VITE_ADMIN_API_BASE_URL=https://api.tbnliving.com
VITE_API_WITH_CREDENTIALS=true
```

## Registro / login SPA (sin 419)

Desde `admin.tbnliving.com` o `front.tbnliving.com`:

1. `GET https://api.tbnliving.com/sanctum/csrf-cookie` (`withCredentials: true`)
2. `POST https://api.tbnliving.com/api/register` (o `/api/v1/register`) con header `X-XSRF-TOKEN`

Variables front/panel: `VUE_APP_API_WITH_CREDENTIALS=true`, cookies en dominio `.tbnliving.com`.

Panel (registro socio): `memberPublicApi.register()` en `panel/src/services/api/memberPublic.api.js`.

## Autenticación miembros (`front.tbnliving.com`)

1. `POST /api/v1/auth/login` — email, password, `country_code`, opcional `device_name`
2. Respuesta: `token`, `user` (también dentro de `data` para formato estándar)
3. Header: `Authorization: Bearer {token}`
4. `GET /api/v1/auth/me` — validar sesión (bootstrap en `main.js`)
5. `POST /api/v1/auth/logout`
6. Resto de recursos: mismas rutas relativas bajo `/api/v1` (`/me`, `/wallet`, `/orders`, `/admin/*` para rol empresa)

Variables front:

```env
VUE_APP_API_ROOT=https://api.tbnliving.com
VUE_APP_API_URL=https://api.tbnliving.com/api/v1
VUE_APP_API_WITH_CREDENTIALS=true
```

## Sanctum + sesión (producción multi-subdominio)

```env
SANCTUM_STATEFUL_DOMAINS=admin.tbnliving.com,front.tbnliving.com,api.tbnliving.com
SESSION_DOMAIN=.tbnliving.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none
CORS_ALLOWED_ORIGINS=https://admin.tbnliving.com,https://front.tbnliving.com
CORS_SUPPORTS_CREDENTIALS=true
```

Backend: `statefulApi()`, `CrossDomainCsrfCookieController` (cookie CSRF en dominio padre).

Tras cambiar `.env` en el servidor:

```bash
php artisan config:clear
php artisan config:cache
```

## Formato JSON estándar

```json
{
  "success": true,
  "message": "…",
  "data": {}
}
```

Helper: `App\Support\ApiResponse`. Login panel y miembros incluyen campos planos además del envoltorio.

## Módulos backend existentes

| Módulo | Ubicación |
|--------|-----------|
| Auth miembros v1 | `App\Http\Controllers\Api\V1\Member\V1MemberAuthController` |
| Auth panel v1 | `App\Http\Controllers\Api\V1\Admin\AdminV1AuthController` |
| Admin | `App\Http\Controllers\Api\Admin\*` |
| MLM Binario | `BinaryTreeService`, `BinaryService`, jobs programados |
| Comisiones | `CommissionService`, `CommissionEngine` |
| Wallet | `WalletService`, `WithdrawalService` |
| Interno | `/api/internal/*` + `X-Internal-Token` |

## Probar analytics / KPIs (curl)

Tras login en el panel, copia el token de `localStorage` (`mlm_admin_token`) o usa sesión + CSRF.

```bash
# KPIs del dashboard
curl -s "https://api.tbnliving.com/api/v1/admin/dashboard/kpis" \
  -H "Authorization: Bearer TU_TOKEN" \
  -H "Accept: application/json"

# Top productos (analytics)
curl -s "https://api.tbnliving.com/api/v1/analytics/products/top?limit=10" \
  -H "Authorization: Bearer TU_TOKEN" \
  -H "Accept: application/json"
```

En el servidor (logs):

```bash
tail -f storage/logs/laravel.log
```

## Roadmap pendiente

1. **Activity logs** — tabla `activity_logs`, middleware de auditoría en rutas sensibles.
2. **OpenAPI / Postman** — colección generada desde rutas v1.

## Colas y despliegue

```bash
php artisan queue:work --tries=3
php artisan schedule:run
php artisan migrate --force
php artisan config:cache
```

Document root de `api.tbnliving.com` → `public/`.
