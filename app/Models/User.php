<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Services\MemberCodeService;
use App\Services\Mail\MemberNotificationService;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'referral_code',
        'document_id',
        'phone',
        'birth_date',
        'sponsor_id',
        'preferred_binary_leg',
        'mlm_role',
        'account_status',
        'is_mlm_qualified',
        'last_qualification_month',
        'monthly_qualifying_pv',
        'lifetime_qualifying_pv',
        'lifetime_purchase_pv',
        'rank_id',
        'member_code',
        'country_code',
        'country_id',
        'registration_package_id',
        'preferred_payment_method',
        'activation_paid_at',
        'account_type',
        'email_verified_at',
        'last_mlm_activity_at',
        'meta',
        'google2fa_secret',
        'two_factor_enabled',
        'two_factor_confirmed_at',
        'two_factor_backup_codes',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret',
        'two_factor_backup_codes',
    ];

    protected $appends = [
        'can_access_admin_panel',
        'needs_activation_subscription',
        'needs_binary_placement',
        'is_preferred_customer',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'is_mlm_qualified' => 'boolean',
            'monthly_qualifying_pv' => 'decimal:2',
            'lifetime_qualifying_pv' => 'decimal:2',
            'lifetime_purchase_pv' => 'decimal:2',
            'activation_paid_at' => 'datetime',
            'last_mlm_activity_at' => 'datetime',
            'meta' => 'array',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_backup_codes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if ($user->member_code !== null && $user->referral_code !== null) {
                return;
            }
            app(MemberCodeService::class)->assignToNewUser($user);
        });
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'sponsor_id');
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function binaryPlacement(): HasOne
    {
        return $this->hasOne(BinaryPlacement::class, 'user_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function registrationPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'registration_package_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** Incluye roles listados en `mlm.admin_roles` (p. ej. admin, superadmin, support). */
    public function canAccessAdminPanel(): bool
    {
        return in_array($this->mlm_role ?? '', config('mlm.admin_roles', ['admin', 'superadmin', 'support']), true);
    }

    protected function getCanAccessAdminPanelAttribute(): bool
    {
        return $this->canAccessAdminPanel();
    }

    /** Miembro sin pago de activación (pedido con paquete completado). */
    protected function getNeedsActivationSubscriptionAttribute(): bool
    {
        if ($this->canAccessAdminPanel() || $this->isPreferredCustomer()) {
            return false;
        }

        return $this->activation_paid_at === null;
    }

    public function isPreferredCustomer(): bool
    {
        return ($this->account_type ?? 'member') === 'preferred_customer';
    }

    protected function getIsPreferredCustomerAttribute(): bool
    {
        return $this->isPreferredCustomer();
    }

    /** Tras activar, elegir pierna bajo el patrocinador en el binario. */
    protected function getNeedsBinaryPlacementAttribute(): bool
    {
        if ($this->isPreferredCustomer()) {
            return false;
        }
        if ($this->canAccessAdminPanel() || ! $this->sponsor_id) {
            return false;
        }
        if ($this->activation_paid_at === null) {
            return false;
        }

        return ! $this->binaryPlacement()->exists();
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function sendEmailVerificationNotification(): void
    {
        app(MemberNotificationService::class)->sendEmailVerification($this);
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->email;
    }
}
