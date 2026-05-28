<?php

namespace App\Services\Wallet;

use App\Events\WithdrawalCreated;
use App\Mail\WithdrawalOtpMail;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalOtp;
use App\Models\SecurityLog;
use App\Repositories\WithdrawalRepository;
use App\Services\Security\SecurityLogService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SecureWithdrawalService
{
    public function __construct(
        protected WalletService $walletService,
        protected WithdrawalOtpService $otpService,
        protected WithdrawalRepository $withdrawalRepository,
        protected SecurityLogService $securityLog,
    ) {}

    public function calculateFee(string $amount): string
    {
        $percent = (float) config('mlm.withdrawals.fee_percent', 0);
        $fixed = bcadd((string) config('mlm.withdrawals.fee_fixed', '0'), '0', 2);
        $fromPercent = $percent > 0
            ? bcdiv(bcmul($amount, (string) $percent, 4), '100', 2)
            : '0.00';

        return bcadd($fromPercent, $fixed, 2);
    }

    public function netAmount(string $amount, string $fee): string
    {
        $net = bcsub($amount, $fee, 2);

        return bccomp($net, '0', 2) === 1 ? $net : '0.00';
    }

    /**
     * @return array{otp: WithdrawalOtp, plain: string, masked_email: string}
     */
    public function requestOtp(User $user, string $amount, string $password, ?string $notes, Request $request): array
    {
        $this->assertUserCanWithdraw($user, $amount);
        $this->assertPassword($user, $password);

        $amount = bcadd($amount, '0', 2);
        $fee = $this->calculateFee($amount);
        $net = $this->netAmount($amount, $fee);

        $plain = $this->otpService->generatePlainCode();
        $otp = $this->otpService->issue(
            $user,
            $plain,
            [
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $net,
                'notas' => $notes,
            ],
            $request->ip(),
            $request->userAgent()
        );

        $this->securityLog->fromRequest($request, $user, SecurityLog::ACTION_WITHDRAW_OTP_REQUEST, [
            'withdrawal_otp_id' => $otp->id,
            'amount' => $amount,
        ]);

        Mail::to($user->email)->send(new WithdrawalOtpMail(
            code: $plain,
            userName: (string) $user->name,
            amount: $amount,
            expiresMinutes: $this->otpService->ttlMinutes(),
        ));

        return [
            'otp' => $otp,
            'plain' => $plain,
            'masked_email' => $this->maskEmail($user->email),
        ];
    }

    public function verifyAndCreate(User $user, string $plainOtp, Request $request): Withdrawal
    {
        $otp = $this->otpService->verify($user, $plainOtp);
        if (! $otp) {
            $this->securityLog->fromRequest($request, $user, SecurityLog::ACTION_WITHDRAW_OTP_VERIFY_FAIL, []);
            throw ValidationException::withMessages([
                'otp' => ['Código inválido, expirado o sin intentos disponibles.'],
            ]);
        }

        $payload = $otp->payload ?? [];
        $amount = bcadd((string) ($payload['amount'] ?? '0'), '0', 2);
        $fee = bcadd((string) ($payload['fee'] ?? '0'), '0', 2);
        $net = bcadd((string) ($payload['net_amount'] ?? $amount), '0', 2);
        $notes = isset($payload['notas']) ? (string) $payload['notas'] : null;

        $this->assertUserCanWithdraw($user, $amount);

        return DB::transaction(function () use ($user, $otp, $amount, $fee, $net, $notes, $request) {
            $w = $this->withdrawalRepository->createFromOtpSession(
                $user,
                $amount,
                $fee,
                $net,
                $notes,
                (int) $otp->id,
                $request->ip(),
                $request->userAgent(),
                'wd:otp:'.Str::uuid()->toString()
            );

            $this->walletService->registrarRetencion(
                $user,
                $amount,
                $w->id,
                "withdrawal:hold:{$w->id}"
            );

            $this->otpService->markUsed($otp);

            $this->securityLog->fromRequest($request, $user, SecurityLog::ACTION_WITHDRAW_OTP_VERIFY_OK, [
                'withdrawal_id' => $w->id,
            ]);
            $this->securityLog->fromRequest($request, $user, SecurityLog::ACTION_WITHDRAW_CREATED, [
                'withdrawal_id' => $w->id,
                'amount' => $amount,
            ]);

            event(new WithdrawalCreated($w));

            return $w->fresh();
        });
    }

    public function resendOtp(User $user, Request $request): array
    {
        $active = $this->otpService->findActiveForUser($user);
        if (! $active) {
            throw ValidationException::withMessages([
                'otp' => ['No hay una solicitud activa. Inicia el retiro de nuevo.'],
            ]);
        }

        $wait = $this->otpService->secondsUntilResendAllowed($active);
        if ($wait > 0) {
            throw ValidationException::withMessages([
                'cooldown' => ["Espera {$wait} segundos antes de reenviar el código."],
            ]);
        }

        $payload = $active->payload ?? [];
        $amount = (string) ($payload['amount'] ?? '0');
        $plain = $this->otpService->generatePlainCode();

        $otp = $this->otpService->issue(
            $user,
            $plain,
            $payload,
            $request->ip(),
            $request->userAgent()
        );

        $this->securityLog->fromRequest($request, $user, SecurityLog::ACTION_WITHDRAW_OTP_RESEND, [
            'withdrawal_otp_id' => $otp->id,
        ]);

        Mail::to($user->email)->send(new WithdrawalOtpMail(
            code: $plain,
            userName: (string) $user->name,
            amount: $amount,
            expiresMinutes: $this->otpService->ttlMinutes(),
        ));

        return [
            'otp' => $otp,
            'masked_email' => $this->maskEmail($user->email),
        ];
    }

    public function assertUserCanWithdraw(User $user, string $amount): void
    {
        if ($user->canAccessAdminPanel()) {
            throw ValidationException::withMessages([
                'user' => ['Las cuentas administrativas no pueden solicitar retiros desde el panel de socio.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Debes verificar tu correo antes de solicitar un retiro.'],
            ]);
        }

        if ((string) $user->account_status !== 'active') {
            throw ValidationException::withMessages([
                'account' => ['Tu cuenta debe estar activa para retirar fondos.'],
            ]);
        }

        $min = bcadd((string) config('mlm.withdrawals.min_amount', '1'), '0', 2);
        $max = bcadd((string) config('mlm.withdrawals.max_amount', '50000'), '0', 2);

        if (bccomp($amount, $min, 2) === -1) {
            throw ValidationException::withMessages([
                'amount' => ["El monto mínimo de retiro es {$min} ".config('mlm.withdrawals.currency', 'BOB').'.'],
            ]);
        }

        if (bccomp($amount, $max, 2) === 1) {
            throw ValidationException::withMessages([
                'amount' => ["El monto máximo de retiro es {$max} ".config('mlm.withdrawals.currency', 'BOB').'.'],
            ]);
        }

        $available = $this->walletService->saldoDisponible($user);
        if (bccomp($available, $amount, 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => ['Saldo insuficiente en billetera.'],
            ]);
        }
    }

    public function assertPassword(User $user, string $password): void
    {
        if ($password === '' || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Contraseña incorrecta.'],
            ]);
        }
    }

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', strtolower(trim($email)), 2);
        if (count($parts) !== 2) {
            return '***';
        }
        $local = $parts[0];
        $masked = strlen($local) <= 2
            ? str_repeat('*', strlen($local))
            : substr($local, 0, 2).str_repeat('*', max(1, strlen($local) - 2));

        return $masked.'@'.$parts[1];
    }
}
