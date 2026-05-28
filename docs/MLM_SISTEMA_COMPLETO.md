# MLM binario — sistema completo (lógica de negocio)

Este documento resume **cómo funciona** el núcleo MLM después de la capa de servicios: árbol, comisiones, wallet, pedidos/facturas, calificación y estadísticas. Los **controladores** solo delegan; la lógica vive en `app/Services`.

---

## 1. Flujo crítico: pedido completado

1. El modelo `Order` pasa a `completado` (`markCompleted`).
2. Se dispara el evento `OrderCompleted`.
3. El listener en cola `QueueMlmProcessingOnOrderCompleted` llama a **`OrderService::procesarOrdenFinalizada($order)`**:
   - **`InvoiceService::emitirDesdeOrdenSiNoExiste`**: factura idempotente (`INV-{order_id}-…`), ítems desde `order_items`, impuesto según `config('mlm.invoice.default_tax_rate')`.
   - **`ProcessOrderMlmAccrualsJob`**: procesamiento MLM asíncrono.

4. El job carga la orden, activa paquete si aplica, **coloca en binario** (`BinaryService::placeUserInFirstFreeSlot`), ejecuta **`CommissionService::procesarAcreditacionesPorPedido`** (BIR + residual), **volumen binario** (`acumularVolumenBinarioPorPedido`) y **calificación mensual** (`UserQualificationService`).

**Idempotencia:** comisiones usan `idempotency_key` en `commission_events` y claves análogas en `wallet_transactions`.

---

## 2. Árbol binario

- **Colocación:** `BinaryService::placeUserInFirstFreeSlot` / **`BinaryTreeService::insertarEnArbol`** — BFS bajo el `sponsor_id`: primero izquierda libre, luego derecha; si el patrocinador está lleno, se desciende por niveles.
- **Propagación de volumen:** `acumularVolumenBinarioPorPedido` recorre la cadena `ancestrosBinarioConPierna` y suma el PV de la orden:
  - **Legacy (periodos):** `binary_leg_volume_weekly` (semana ISO o mes).
  - **Híbrido diario (B):** `binary_leg_volume_daily` (YYYY-MM-DD) + carry diario.
- **Cierre (legacy):** `BinaryService::procesarCierreSemanal` calcula carry de la semana anterior + volumen actual, empareja pierna débil (`min(L,R)`), arrastra el resto a `binary_weekly_carry`.
- **Cierre (híbrido diario B):** `BinaryHybridDailyService` calcula:
  - **Diario:** `matchedPV_día = min(leftEff, rightEff)` y guarda `binary_daily_payouts` + `binary_daily_carry` (día siguiente).
  - **Semanal:** suma `daily_bonus_bob`, aplica **tope semanal** y registra comisión `binary` del periodo ISO.

### Binario — pago (comisión)

- **Modo actual (recomendado):**  
  `payout = matched_pv × bob_per_pv × matched_pv_commission_rate`  
  con `MLM_BINARY_BOB_PER_PV` / `MLM_BOB_PER_PV` y `MLM_BINARY_MATCHED_RATE` (por defecto **0.21** = 21 % sobre el valor en moneda del PV emparejado).
- **Modo legacy:** `MLM_BINARY_LEGACY_FLAT=true` usa solo `payout_per_matched_pv` (comportamiento anterior).

Los metadatos del evento `binary` guardan fórmula y parámetros para auditoría.

---

## 3. Bono inicio rápido (BIR)

- Solo líneas con **paquete** (`package_id`).
- Por defecto **`mlm.bir.base = pv`**: base = **PV de la línea** (`order_items.pv_points`).
- Importe en moneda: **`(PV × % nivel) × bob_per_pv`** con niveles **21 % / 15 % / 6 %** sobre patrocinio en cadena (3 niveles).
- **Regla de negocio:** se paga **solo en la inscripción** (primer pedido completado del socio con paquete).
- Alternativa: `MLM_BIR_BASE=commissionable_amount` usa el monto comisionable del paquete.

`bob_per_pv` = `config('mlm.pv_value.bob_per_pv')` (p. ej. 9 BOB por PV).

---

## 4. Residual (unilevel por rango efectivo)

- Base: **PV del pedido** (`order.total_pv`).
- **Rango efectivo** del beneficiario: el **mayor** umbral en `mlm.residual.rank_thresholds_pv` tal que `users.monthly_qualifying_pv >= umbral`.
- La matriz **`mlm.residual.matrix_by_rank_slug`** define el % por generación (1…12). Si no aplica ningún umbral, se usa **`default_generations`**.

Ajuste fino de umbrales y slugs debe alinearse con los registros en la tabla `ranks`.

---

## 5. Wallet

- **`WalletService`**: créditos (`acreditar`), débitos, retenciones y liberaciones con `idempotency_key`.
- **Saldo disponible:** suma tipos `credit`, `retention_release`, menos `debit` y `retention`.
- Cada comisión crea **`commission_events`** + **`commissions`** + movimiento de wallet enlazado.

---

## 6. Retiros

- **`WithdrawalService::solicitar`**: valida saldo, crea retiro `pendiente` y **retención** en wallet.
- **Aprobar / rechazar** (admin): aprueba → job de pago final; rechazo → libera retención.

---

## 7. Calificación y ciclo (1–27)

- `config('mlm.qualification_cycle')`: ventana de negocio para mensajes de “días restantes” (usado en **`MlmBonusProgressService`**).
- PV mensual del usuario se actualiza en **`UserQualificationService::actualizarCalificacionMensual`**.

---

## 8. Estadísticas de bonos (API)

- **`GET /me/dashboard`** incluye **`bonus_progress`**: pierna débil, PV izq/der semana actual, progreso % hacia el **siguiente umbral** de PV de carrera, referidos directos activos, días restantes en ciclo.

---

## 9. Liderazgo (racha 3 meses)

- **Snapshots:** `UserQualificationService` persiste `user_monthly_rank_snapshots` con PV de calificación y racha (meses consecutivos mismo rango).
- **Cálculo/acreditación:** `LeadershipBonusAccrualService` + job mensual `CalculateLeadershipMonthlyBonusesJob`:
  - teamPV mensual (light) = PV propio del mes + PV de directos del mes (excluye clientes preferentes).
  - si `teamPV >= requiredPV` y `streak >= N`:
    - bonusPV = requiredPV × leadershipRate
    - bonusBOB = bonusPV × bob_per_pv
  - acredita `commission_events.type = leadership` con `period_type = monthly`.

---

## 10. Migraciones y entorno

- Facturas: `invoices`, `invoice_items`; migración `2026_04_02_100000_*` añade impuestos, `package_id` y `product_id` nullable (MySQL).
- Variables útiles: `MLM_BOB_PER_PV`, `MLM_BINARY_MATCHED_RATE`, `MLM_BINARY_LEGACY_FLAT`, `MLM_INVOICE_TAX_PCT`, `MLM_BIR_BASE`.

---

## 11. Ejecución manual (ejemplos)

```php
// Orquestación tras completar pedido (normalmente vía listener)
app(\App\Services\OrderService::class)->procesarOrdenFinalizada($order);

// Solo acreditaciones MLM (job ya lo encapsula)
app(\App\Services\CommissionService::class)->procesarAcreditacionesPorPedido($order);

// Cierre binario semanal (scheduler / comando)
app(\App\Services\BinaryService::class)->procesarCierreSemanal($weekKey, app(\App\Services\CommissionService::class));
```

---

## 12. Referencias de código

| Área | Servicios / clases |
|------|---------------------|
| Orquestación pedido | `OrderService`, `InvoiceService` |
| Árbol | `BinaryService`, `BinaryTreeService` |
| Comisiones | `CommissionService` |
| Wallet | `WalletService` |
| Calificación | `UserQualificationService` |
| Progreso UI | `MlmBonusProgressService` |
| Liderazgo (ext.) | `LeadershipStreakService`, `LeadershipService` |

---

*Última actualización: documentación alineada con la lógica en `app/Services` y `config/mlm.php`.*
