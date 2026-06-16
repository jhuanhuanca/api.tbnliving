# Despliegue en producción (TBN Living)

Dominios actuales:

| Servicio | URL |
|----------|-----|
| API Laravel | `https://api.tbnliving.com` |
| App miembros | `https://front.tbnliving.com` |
| Panel admin | `https://admin.tbnliving.com` |

> **Importante:** No uses `app.imparablesjhn.shop` ni `imparablesjhn.shop`. Es un despliegue anterior con otra base de datos.

## 1. Backend (`api.tbnliving.com`)

1. Copiar `test-back-synkai-main`, `composer install --no-dev --optimize-autoloader`.
2. `.env` de producción:
   - `APP_URL=https://api.tbnliving.com`
   - `FRONTEND_URL=https://front.tbnliving.com`
   - `CORS_ALLOWED_ORIGINS=https://admin.tbnliving.com,https://front.tbnliving.com,https://tbnliving.com`
   - `SANCTUM_STATEFUL_DOMAINS=admin.tbnliving.com,front.tbnliving.com,api.tbnliving.com,...`
   - `SESSION_DOMAIN=.tbnliving.com`
3. `php artisan migrate --force`
4. Cola: `php artisan queue:work --tries=3`
5. Cron: `* * * * * cd /ruta/al/proyecto && php artisan schedule:run`

## 2. Frontend miembros (`front.tbnliving.com`)

1. En el servidor, archivo **`test-front_synkai-main/.env.production`** (obligatorio antes del build):

```env
VUE_APP_API_ROOT=https://api.tbnliving.com
VUE_APP_API_URL=https://api.tbnliving.com/api/v1
VUE_APP_API_WITH_CREDENTIALS=true
```

2. Build:

```bash
cd /var/www/tbnliving/front
npm ci
npm run build
```

El script `prebuild` imprime las URLs de registro y **falla** si detecta dominios legacy.

3. Servir `dist/` con Nginx (`try_files $uri $uri/ /index.html;`).

### Verificar en el navegador

En DevTools → Network, al registrarte debe aparecer:

- `POST https://api.tbnliving.com/sanctum/csrf-cookie`
- `POST https://api.tbnliving.com/api/register`

Si ves `front.tbnliving.com/api/register` o `app.imparablesjhn.shop`, el build está mal o Nginx redirige al API antiguo.

## 3. Registro (endpoints)

| Formulario | Método | URL |
|------------|--------|-----|
| Socio MLM (`Signup.vue`) | POST | `https://api.tbnliving.com/api/register` |
| Cliente preferente | POST | `https://api.tbnliving.com/api/register/preferred-customer` |
| Login | POST | `https://api.tbnliving.com/api/v1/auth/login` |
