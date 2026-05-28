<?php

namespace App\Services\Security;

use App\Models\User;
use App\Repositories\SecurityLogRepository;
use Illuminate\Http\Request;

class SecurityLogService
{
    public function __construct(
        protected SecurityLogRepository $repository
    ) {}

    public function fromRequest(Request $request, ?User $user, string $action, array $metadata = []): void
    {
        $this->repository->log(
            $user,
            $action,
            $request->ip(),
            $request->userAgent(),
            $metadata
        );
    }

    public function log(?User $user, string $action, ?string $ip, ?string $userAgent, array $metadata = []): void
    {
        $this->repository->log($user, $action, $ip, $userAgent, $metadata);
    }
}
