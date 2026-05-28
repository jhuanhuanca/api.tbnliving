# MLM Back Office — arquitectura y cambios

## Resumen

Se unificó la identidad en la tabla `users` (se eliminó la duplicación con `usuarios`), se normalizó el ledger de wallet, se añadieron tablas para binario, cierres de periodo y auditoría de comisiones, y se implementó el flujo **Registro → Compra → Activación (evento) → Comisiones (cola) → Wallet → Retiro (retención) → Admin → Pago**.

### Códigos de socio consecutivos (10 – 1.000.000)

- Tabla `mlm_member_code_counter` (fila única `next_assignable`, inicio 10).
- `MemberCodeService` asigna `member_code` y `referral_code` (mismo valor en string) en `User::creating` con `lockForUpdate`.
- `GET /api/public/sponsors/{code}` valida patrocinador. Registro: `POST /api/register` con `sponsor_referral_code` opcional.

## Base de datos

| Área | Decisiones |
|------|------------|
| **Usuarios** | Campos MLM (`sponsor_id`, `rank_id`, PV mensual, `referral_code`, etc.) en `users` con índices en `sponsor_id`. |
| **Binario** | `binary_placements` (hijo → padre + pierna). Volumen semanal agregado en `binary_leg_volume_weekly` (clave `parent_user_id` + `week_key` + `leg_position`) para evitar recorridos masivos en tiempo real. |
| **Carry binario** | `binary_weekly_carry` guarda el sobrante tras cada cierre (pierna débil = pago; excedente persiste). |
| **Idempotencia pedido** | `order_binary_volume_applied` evita duplicar volumen binario por `order_id`. |
| **Comisiones** | `commission_events` es inmutable (auditoría + `idempotency_key` única). `commissions` enlaza al evento y al movimiento de wallet para reportes. |
| **Wallet** | `wallets` sin saldos “maestros”; el disponible se deriva de `wallet_transactions` (`credit`, `debit`, `retention`, `retention_release`). |
| **Retiros** | Estados: pendiente → aprobado → completado / rechazado. Retención contable al solicitar. |
| **Productos** | Tabla `categories`; `products.pv_points` numérico; `packages` con `slug`, `pv_points` y `commissionable_amount` opcional. |

## Backend

- **Config** `config/mlm.php`: BIR sobre PV × BOB/PV, binario (21 % sobre PV emparejado × BOB/PV o modo legacy `payout_per_matched_pv`), residual por matriz de rango, colas, ciclo de calificación, **roles** y **`admin_roles`**. Detalle operativo: **`docs/MLM_SISTEMA_COMPLETO.md`**.
- **Servicios**: `OrderService` (factura + encolado MLM), `InvoiceService`, `CommissionService`, `BinaryService`, `BinaryTreeService`, `MlmBonusProgressService`, `WalletService`, `WithdrawalService`, `UserQualificationService`, `LeadershipStreakService` (extensión), **`LeadershipService`** (reportes).
- **Autorización**: **Policies** `WithdrawalPolicy`, `BinaryPlacementPolicy` (`approve` / `reject` / `viewAny` / `create`) usando `User::canAccessAdminPanel()` (alineado con `mlm.admin_roles`).
- **Middleware** `mlm.admin` (`EnsureMlmRole`): sin parámetro usa `config('mlm.admin_roles')`.
- **Eventos**: `OrderCompleted` → listener en cola → `OrderService::procesarOrdenFinalizada` (factura idempotente + `ProcessOrderMlmAccrualsJob`: BIR, residual, volumen binario, calificación). `UserActivated` al cruzar el umbral de PV.
- **Jobs**: `CalculateBinaryCommissionsJob`, `ProcessResidualCommissionsJob`, `ProcessWithdrawalsJob`.
- **Scheduler** (`bootstrap/app.php`): domingo 03:00 cierre binario semana anterior; día 1 04:00 cierre mensual (auditoría residual).
- **Comandos**: `mlm:close-weekly-binary`, `mlm:close-monthly-residual`.

## API (Sanctum)

**Público / socio**

- `POST /register`, `POST /login`, `GET /public/sponsors/{code}`, `GET /packages`
- `GET /me`, `/me/dashboard`, `/me/referrals`, `/me/commissions`
- `GET /wallet/balance`, `GET /wallet/transactions`
- `GET /orders`, `POST /orders`
- `POST /withdrawals` (propio usuario)

**Empresa** — `Authorization: Bearer` + rol en `mlm.admin_roles` (middleware `mlm.admin`), prefijo `/api/admin`:

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/admin/dashboard` | Conteos: usuarios, retiros pendientes, pedidos hoy |
| GET | `/admin/withdrawals` | Lista paginada (`estado`, `per_page`) |
| POST | `/admin/withdrawals/{withdrawal}/approve` | Aprobar retiro |
| POST | `/admin/withdrawals/{withdrawal}/reject` | Rechazar (`notas_admin` opcional) |
| POST | `/admin/binary-placement` | Colocación binaria (Policy `create`) |
| GET | `/admin/reconciliation/period-closures` | Cierres de periodo (`period_closures`) |
| GET | `/admin/reconciliation/commission-summary` | Agregados por `type`, `period_key`, `period_type` (query opcional) |
| GET | `/admin/leadership/{monthKey}` | Resumen liderazgo mensual (`YYYY-MM`) |

El modelo `User` expone **`can_access_admin_panel`** en JSON (append) para el front.

## Rendimiento y escala

- Cálculos pesados en **colas**; acumulación binaria O(profundidad) por pedido.
- **Cache** de ancestros binarios (`BinaryService::ancestrosBinarioConPierna`) con TTL configurable; invalidación al aplicar volumen o colocar nodo.
- Para millones de usuarios: particionar por `week_key` / `period_key`, lecturas con réplicas, Redis (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`) y workers horizontales.

## Seguridad

- Claves idempotentes en comisiones y movimientos de wallet.
- Transacciones + `lockForUpdate` en finalización de retiros.
- Montos con `bcmath` en servicios críticos.
- **No** comprobar `mlm_role` a mano en controladores: Policies + middleware `mlm.admin`.

## Frontend (Vue)

- Cliente HTTP: `src/services/api.js` (`VUE_APP_API_URL` o `/api` con proxy).
- `vue.config.js`: proxy `/api` → backend Laravel (ajustar con `VUE_APP_BACKEND_ORIGIN`).
- `src/services/admin.js`: llamadas al prefijo `/admin/*`.
- Rutas **`/admin/dashboard`**, **`/admin/retiros`**, **`/admin/reconciliacion`** con `meta.requiresAdmin` (solo si `user.can_access_admin_panel`).
- Vuex `auth`: getter `canAccessAdmin`; menú lateral muestra **Panel empresa** cuando aplica.
- Tras login sin `?redirect=`, si `can_access_admin_panel` se redirige a `/admin/dashboard`; si no, a `/dashboard-default`.

### Cómo acceder: administración de empresa vs socio común

| Quién | Cómo | Qué verás |
|-------|------|-----------|
| **Socio** (`mlm_role = member`) | Misma URL del front (ej. `http://localhost:8080`), **Iniciar sesión** con un usuario normal (tras seed: `demo@empresa.com` / `password`). | Dashboard y menú de red, ganancias, compras, etc. **No** aparece “Panel empresa”; rutas `/admin/*` redirigen al dashboard de socio si entras sin permiso. |
| **Administración empresa** (`mlm_role = admin` o rol incluido en `MLM_ADMIN_ROLES`) | Misma app y misma pantalla de login: **`admin@empresa.com`** / `password` (seed). Tras entrar: enlace **Panel empresa** en el menú o URL directa `/admin/dashboard`. | Panel con métricas, retiros pendientes, reconciliación y resumen de liderazgo. El API rechaza con 403 si el token no es de un rol administrativo. |

**Nota:** Si cambias roles en base de datos, el usuario debe **volver a iniciar sesión** para que el JSON del usuario (y `can_access_admin_panel`) se actualice en el navegador.

## Próximos pasos opcionales

- UI más rica para aprobación masiva de retiros y export CSV de reconciliación.
- Rol `support` con Policies distintas (solo lectura) si hace falta granularidad fina.
