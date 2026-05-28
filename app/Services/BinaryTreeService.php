<?php

namespace App\Services;

use App\Models\BinaryPlacement;
use App\Models\User;

/**
 * API explícita de negocio para colocación en el binario (delega en BinaryService).
 */
class BinaryTreeService
{
    public function __construct(
        protected BinaryService $binaryService
    ) {}

    /**
     * Inserta en automático delegando en {@see BinaryService::placeUserAutoUnderSponsor()}.
     * Para pierna izq/der usar {@see BinaryService::placeUserDirectUnderSponsor()}.
     */
    public function insertarEnArbol(User $user): ?BinaryPlacement
    {
        return $this->binaryService->placeUserAutoUnderSponsor($user);
    }
}
