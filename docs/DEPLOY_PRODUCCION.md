# Despliegue en producción (MLM back office)

Dominios de referencia del proyecto:

| Servicio | URL |
|----------|-----|
| Frontend (Vue) | `https://imparablesjhn.shop` |
| Backend (Laravel API) | `https://app.imparablesjhn.shop` |

## 1. Backend (`app.imparablesjhn.shop`)

1. Clonar/copiar `test-back-synkai-main`, instalar dependencias: `composer install --no-dev --optimize-autoloader`.
2. Copiar `.env.example` a `.env` y configurar:
   - `APP_URL=https://app.imparablesjhn.shop`
   - `APP_DEBUG=false`
   - `APP_KEY` (`php artisan key:generate`)
   - Base de datos (MySQL recomendado en producción).
   - `CORS_ALLOWED_ORIGINS=https://imparablesjhn.shop,https://www.imparablesjhn.shop`
   - `SANCTUM_STATEFUL_DOMAINS` incluyendo el front y dominios locales si aún desarrollas.
   - `QUEUE_CONNECTION=database` (o `redis` si lo tienes).
3. `php artisan migrate --force`
4. `php artisan db:seed` (o solo `MlmBootstrapSeeder` según necesidad).
5. **Cola de trabajos** (obligatorio para comisiones al completar pedidos):  
   `php artisan queue:work --tries=3` (Supervisor/systemd en servidor).  
   Alternativa de prueba: `QUEUE_CONNECTION=sync` ejecuta jobs al mismo tiempo (sin worker; no escala).
6. **Scheduler** (cierres binario / residual programados en `bootstrap/app.php`):  
   crontab: `* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1`
7. Servidor web: apuntar document root a `public/`, HTTPS obligatorio, PHP `>= 8.2`.

## 2. Frontend (`imparablesjhn.shop`)

1. En `test-front_synkai-main`, el archivo `.env.production` define  
   `VUE_APP_API_URL=https://app.imparablesjhn.shop/api`
2. Build: `npm ci` y `npm run build`
3. Subir el contenido de `dist/` al hosting estático del dominio (o Nginx sirviendo `dist`).
4. **Vue Router (history mode)**: el servidor debe devolver `index.html` para rutas como `/dashboard-default`. Ejemplo Nginx:  
   `try_files $uri $uri/ /index.html;`

## 3. Pruebas lógicas MLM

- Registro con código de patrocinador → `sponsor_id` correcto.
- Pedido `POST /api/orders` con productos/paquetes → estado completado → job de comisiones (requiere cola activa).
- PV mensual y umbral `MLM_MONTHLY_ACTIVATION_PV` en `.env`.
- Panel admin (rol `admin` / `superadmin`) → productos, paquetes, retiros, reconciliación.

## 4. Problemas frecuentes

- **CORS**: revisar `CORS_ALLOWED_ORIGINS` y que el front use exactamente la URL del API en el build.
- **401 en todo**: token caducado o inválido; el front redirige a `/signin`.
- **Comisiones en cero**: comprobar `queue:work` y logs `storage/logs`, tabla `failed_jobs`.
