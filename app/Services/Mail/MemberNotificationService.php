<?php

namespace App\Services\Mail;

use App\Mail\TransactionalMail;
use App\Models\Order;
use App\Models\Rank;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\DeliveryNotice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MemberNotificationService
{
    public function sendWelcome(User $user): bool
    {
        if (! $this->enabled('welcome_on_register')) {
            return false;
        }

        $user->loadMissing('sponsor');
        $isPreferente = $user->isPreferredCustomer();
        $frontUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $intro = $isPreferente
            ? 'Tu cuenta de cliente preferente en TBN Living fue creada correctamente. Confirma tu correo si aún no lo hiciste y accede al catálogo con precios especiales.'
            : 'Tu inscripción como socio TBN Living fue registrada. Confirma tu correo si aún no lo hiciste y completa tu activación para comenzar a construir tu red.';

        $body = '<ul style="margin:0;padding-left:20px;color:#475569;font-size:14px;line-height:1.7;">';
        $body .= '<li>Código de socio: <strong>'.e($user->member_code ?? $user->referral_code ?? '—').'</strong></li>';
        if ($user->sponsor) {
            $body .= '<li>Patrocinador: <strong>'.e($user->sponsor->name).'</strong></li>';
        }
        $body .= '</ul>';

        return $this->queueTo(
            $user,
            new TransactionalMail(
                mailSubject: $isPreferente
                    ? 'Bienvenido a TBN Living — Cliente preferente'
                    : 'Bienvenido a TBN Living — Tu inscripción',
                heading: $isPreferente ? '¡Bienvenido!' : '¡Bienvenido a la red!',
                userName: $user->name,
                intro: $intro,
                bodyHtml: $body,
                ctaUrl: $frontUrl,
                ctaLabel: 'Ir al panel',
                footerNote: 'Gracias por confiar en TBN Living.',
            )
        );
    }

    public function sendSponsorReferralNotice(User $newUser, User $sponsor): bool
    {
        if (! $this->enabled('sponsor_on_referral')) {
            return false;
        }

        $newUser->loadMissing('registrationPackage');
        $isPreferente = $newUser->isPreferredCustomer();

        $intro = $isPreferente
            ? 'Un nuevo cliente preferente se registró con tu código de patrocinador.'
            : '¡Felicitaciones! Un nuevo socio se inscribió en tu red directa.';

        $body = '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin:16px 0;">';
        $body .= '<p style="margin:0 0 8px;color:#334155;font-size:14px;"><strong>Nombre:</strong> '.e($newUser->name).'</p>';
        $body .= '<p style="margin:0 0 8px;color:#334155;font-size:14px;"><strong>Correo:</strong> '.e($newUser->email).'</p>';
        $body .= '<p style="margin:0;color:#334155;font-size:14px;"><strong>Código:</strong> '.e($newUser->member_code ?? $newUser->referral_code ?? '—').'</p>';
        if (! $isPreferente && $newUser->registrationPackage) {
            $body .= '<p style="margin:8px 0 0;color:#334155;font-size:14px;"><strong>Paquete:</strong> '.e($newUser->registrationPackage->name).'</p>';
        }
        $body .= '</div>';
        $body .= '<p style="margin:0;color:#64748b;font-size:13px;">Acompáñalo en sus primeros pasos para impulsar juntos el crecimiento de tu organización.</p>';

        return $this->queueTo(
            $sponsor,
            new TransactionalMail(
                mailSubject: $isPreferente
                    ? 'Nuevo cliente preferente referido — TBN Living'
                    : '¡Nuevo socio inscrito en tu red! — TBN Living',
                heading: $isPreferente ? 'Nuevo referido' : '¡Nuevo socio!',
                userName: $sponsor->name,
                intro: $intro,
                bodyHtml: $body,
            )
        );
    }

    public function sendOrderPurchaseConfirmation(Order $order): bool
    {
        if (! $this->enabled('order_purchase')) {
            return false;
        }

        $order->loadMissing(['user', 'items.product', 'items.package']);
        $buyer = $order->user;
        if (! $buyer) {
            return false;
        }

        $hasProducts = $order->items->contains(fn ($it) => $it->product_id !== null);
        $deliveryNotice = $hasProducts
            ? DeliveryNotice::forOrder($order)
            : 'Tu pedido será procesado. Te contactaremos si necesitamos coordinar algún detalle adicional.';

        $body = '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px;margin:16px 0;">';
        $body .= '<p style="margin:0 0 8px;color:#166534;font-size:14px;"><strong>Pedido #'.e((string) $order->id).'</strong></p>';
        $body .= '<p style="margin:0 0 8px;color:#334155;font-size:14px;">Total: <strong>Bs '.number_format((float) $order->total, 2, '.', ',').'</strong></p>';
        if ((float) ($order->shipping_cost ?? 0) > 0) {
            $body .= '<p style="margin:0 0 8px;color:#334155;font-size:14px;">Incluye envío: Bs '.number_format((float) $order->shipping_cost, 2, '.', ',').'</p>';
        }
        $body .= '</div>';

        if ($order->delivery_mode === 'envio' || filled($order->shipping_direccion)) {
            $body .= '<p style="margin:0 0 8px;color:#475569;font-size:14px;"><strong>Entrega a:</strong><br>';
            $body .= e(trim(implode(', ', array_filter([
                $order->shipping_direccion,
                $order->shipping_ciudad,
                $order->shipping_departamento,
            ])))).'</p>';
        }

        $body .= '<p style="margin:16px 0 0;color:#1e9f57;font-size:14px;font-weight:600;">'.e($deliveryNotice).'</p>';

        return $this->queueTo(
            $buyer,
            new TransactionalMail(
                mailSubject: 'Compra registrada — Pedido #'.$order->id,
                heading: '¡Gracias por tu compra!',
                userName: $buyer->name,
                intro: 'Hemos registrado tu pedido correctamente. Estos son los detalles:',
                bodyHtml: $body,
                footerNote: 'Conserva este correo como referencia de tu compra.',
            )
        );
    }

    public function sendSupportTicketStatus(SupportTicket $ticket, ?string $previousStatus = null): bool
    {
        if (! $this->enabled('support_ticket_status')) {
            return false;
        }

        if ($previousStatus !== null && $previousStatus === $ticket->status) {
            return false;
        }

        $ticket->loadMissing('user');
        $user = $ticket->user;
        if (! $user) {
            return false;
        }

        $body = '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin:16px 0;">';
        $body .= '<p style="margin:0 0 8px;color:#334155;font-size:14px;"><strong>Ticket:</strong> '.e($ticket->code).'</p>';
        $body .= '<p style="margin:0 0 8px;color:#334155;font-size:14px;"><strong>Asunto:</strong> '.e($ticket->subject).'</p>';
        $body .= '<p style="margin:0 0 8px;color:#334155;font-size:14px;"><strong>Estado:</strong> '.e($ticket->status).'</p>';
        $body .= '<p style="margin:0;color:#334155;font-size:14px;"><strong>Prioridad:</strong> '.e($ticket->priority).'</p>';
        $body .= '</div>';

        $statusHint = match ($ticket->status) {
            'En proceso' => 'Nuestro equipo está revisando tu solicitud.',
            'Resuelto' => 'Tu solicitud fue atendida. Si necesitas algo más, abre un nuevo ticket.',
            'Cerrado' => 'Este ticket fue cerrado. Puedes crear uno nuevo si surge otra consulta.',
            default => 'Hemos recibido tu ticket y lo atenderemos a la brevedad.',
        };

        $body .= '<p style="margin:0;color:#64748b;font-size:13px;">'.e($statusHint).'</p>';

        return $this->queueTo(
            $user,
            new TransactionalMail(
                mailSubject: 'Actualización de ticket '.$ticket->code.' — TBN Living',
                heading: 'Estado de tu ticket',
                userName: $user->name,
                intro: 'Tu ticket de soporte fue actualizado:',
                bodyHtml: $body,
            )
        );
    }

    public function sendRankAchievement(User $user, Rank $newRank, ?Rank $oldRank = null): bool
    {
        if (! $this->enabled('rank_achievement')) {
            return false;
        }

        if ($oldRank && (int) ($newRank->sort_order ?? 0) <= (int) ($oldRank->sort_order ?? 0)) {
            return false;
        }

        $body = '<div style="text-align:center;margin:24px 0;">';
        $body .= '<div style="display:inline-block;padding:20px 32px;background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:16px;border:2px solid #f59e0b;">';
        $body .= '<p style="margin:0;color:#92400e;font-size:12px;text-transform:uppercase;letter-spacing:0.1em;font-weight:700;">Nuevo rango</p>';
        $body .= '<p style="margin:8px 0 0;color:#78350f;font-size:24px;font-weight:800;">'.e($newRank->name).'</p>';
        $body .= '</div></div>';
        $body .= '<p style="margin:0;color:#475569;font-size:14px;line-height:1.6;text-align:center;">Tu esfuerzo y el volumen de tu organización te han llevado a este logro. ¡Sigue construyendo tu carrera!</p>';

        return $this->queueTo(
            $user,
            new TransactionalMail(
                mailSubject: '¡Felicitaciones! Nuevo rango: '.$newRank->name,
                heading: '¡Ascenso de rango!',
                userName: $user->name,
                intro: 'Nos complace informarte que alcanzaste un nuevo rango en TBN Living:',
                bodyHtml: $body,
                footerNote: 'El equipo TBN Living celebra contigo este hito.',
            )
        );
    }

    private function enabled(string $key): bool
    {
        if (! filter_var(config('mlm.notifications.email.enabled', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return filter_var(config("mlm.notifications.email.{$key}", true), FILTER_VALIDATE_BOOL);
    }

    private function queueTo(User $user, TransactionalMail $mail): bool
    {
        $email = $user->email;
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            Mail::to($email)->queue($mail);

            return true;
        } catch (\Throwable $e) {
            Log::error('mail.member_notification.failed', [
                'user_id' => $user->id,
                'subject' => $mail->mailSubject,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
