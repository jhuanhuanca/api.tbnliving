# Redis en producción — TBN Living MLM API

Stack: Laravel 12, PHP 8.3, MySQL, Redis 7, Nginx, Ubuntu 24.

## 1. phpredis vs predis

| Cliente | Uso recomendado |
|--------|------------------|
| **phpredis** (extensión PECL) | **Producción** — más rápido, menos CPU |
| **predis/predis** (Composer) | Fallback / CI / dev sin extensión |

```bash
# Producción (Ubuntu 24)
sudo apt install php8.3-redis redis-server
composer install --no-dev --optimize-autoloader
```

`.env`:

```env
REDIS_CLIENT=phpredis
```

Si no hay extensión: `REDIS_CLIENT=predis` (ya en `composer.json`).

## 2. Instalación Redis (Ubuntu 24)

```bash
sudo apt update
sudo apt install redis-server
sudo sed -i 's/^supervised no/supervised systemd/' /etc/redis/redis.conf
# Opcional: bind 127.0.0.1 y requirepass en producción
echo "requirepass TU_PASSWORD_FUERTE" | sudo tee -a /etc/redis/redis.conf
sudo systemctl enable redis-server
sudo systemctl restart redis-server
redis-cli ping
```

## 3. Bases lógicas Redis (separación)

| DB | Conexión Laravel | Uso |
|----|------------------|-----|
| 0 | `default` | locks, misc |
| 1 | `cache` | CACHE_STORE |
| 2 | `queue` | QUEUE_CONNECTION |
| 3 | `session` | SESSION_DRIVER (Sanctum SPA) |

## 4. `.env` producción (api.tbnliving.com)

```env
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=TU_PASSWORD_FUERTE
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
REDIS_SESSION_DB=3

REDIS_QUEUE_CONNECTION=queue
REDIS_CACHE_CONNECTION=cache
SESSION_CONNECTION=session

CACHE_PREFIX=tbn_api_cache_
REDIS_PREFIX=tbn_api_

REDIS_QUEUE_RETRY_AFTER=120
MLM_QUEUE_MAIL=mail
MLM_REDIS_QUEUE_LIST=high,default,mail,binary,residual,withdrawals,low

MLM_CACHE_TTL_ADMIN_DASHBOARD=120
MLM_CACHE_TTL_WALLET_BALANCE=30
MLM_BINARY_CACHE_TTL=600

MLM_LOCK_WALLET_SECONDS=15
MLM_LOCK_ORDER_PAYMENT_SECONDS=20
MLM_LOCK_WITHDRAWAL_SECONDS=30

# Horizon (opcional fase 2)
MLM_HORIZON_ENABLED=false
```

Después:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan mlm:redis-health
```

## 5. Colas Redis + Supervisor

```bash
sudo cp deploy/supervisor/tbn-mlm-workers.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start tbn-mlm-queue:*
sudo supervisorctl status
```

Monitoreo:

```bash
php artisan queue:monitor redis:default,redis:mail,redis:withdrawals
php artisan queue:failed
php artisan queue:retry all
```

## 6. Laravel Horizon (opcional)

Recomendado cuando tengas **>3 workers** y necesites UI de métricas.

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan vendor:publish --provider="Laravel\Horizon\HorizonServiceProvider"
```

Proteger ruta `/horizon` con gate admin. En VPS pequeño, **Supervisor + queue:work** es suficiente (implementado).

## 7. Cache estratégico (implementado en código)

| Recurso | Clave / TTL | Invalidación |
|---------|-------------|--------------|
| Dashboard admin | `admin:dashboard:v1` 120s | Pedido, retiro, wallet |
| Saldo wallet | `wallet:balance:{userId}` 30s | Cualquier movimiento wallet |
| Árbol binario ancestros | `mlm:binary:ancestors:*` | `BinaryService::olvidarCacheArbol` |
| Rangos sort | `mlm:rank_sort_by_slug` | Reevaluación rangos |

Servicios: `App\Services\Cache\*`, locks: `App\Support\Redis\RedisLockService`.

## 8. Wallets — concurrencia

- `lockForUpdate()` en MySQL (fuente de verdad)
- `Cache::lock` Redis por `user_id` en `WalletService`
- `idempotency_key` en transacciones (anti doble pago)
- Cola `after_commit=true` en jobs Redis

## 9. Roadmap migración

### Desarrollo (Laragon)

```env
CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
```

### Staging

1. Redis local en VPS staging
2. `.env` con redis
3. `php artisan mlm:redis-health`
4. 1 worker Supervisor
5. Pruebas: login admin, retiro, pedido, dashboard

### Producción

1. Backup DB
2. Deploy código
3. `composer install --no-dev`
4. `php artisan migrate --force`
5. Cambiar `.env` redis (mantener database como fallback 1h si quieres rollback)
6. `php artisan config:cache`
7. Reiniciar PHP-FPM + workers
8. Smoke: `mlm:redis-health`, login, cola mail

Rollback: revertir `.env` a database drivers y `supervisorctl stop`.

## 10. Monitoreo

```bash
redis-cli INFO memory
redis-cli INFO stats
tail -f storage/logs/laravel.log
tail -f storage/logs/worker.log
```

Alertas sugeridas: memoria Redis >80%, `failed_jobs` >0, queue latency.

## 11. Sanctum / sesiones multi-subdominio

Con `SESSION_DRIVER=redis` y `SESSION_CONNECTION=session`:

```env
SESSION_DOMAIN=.tbnliving.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none
SANCTUM_STATEFUL_DOMAINS=admin.tbnliving.com,front.tbnliving.com,api.tbnliving.com
```

Rate limits usan el mismo cache store → Redis en producción.
