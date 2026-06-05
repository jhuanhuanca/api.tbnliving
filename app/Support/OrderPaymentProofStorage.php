<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class OrderPaymentProofStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'payment-proofs';

    /** @var array<int, string> */
    public const METHODS_REQUIRING_PROOF = [
        'transferencia',
        'qr',
    ];

    /** @var array<int, string> */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
    ];

    public static function requiresProof(string $paymentMethod): bool
    {
        return in_array(strtolower(trim($paymentMethod)), self::METHODS_REQUIRING_PROOF, true);
    }

    public static function existsFor(Order $order): bool
    {
        $path = (string) ($order->payment_proof_path ?? '');

        return $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    public static function store(Order $order, UploadedFile $file): void
    {
        if ($order->payment_proof_path) {
            Storage::disk(self::DISK)->delete($order->payment_proof_path);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::uuid().'.'.$ext;
        $path = $file->storeAs(self::DIRECTORY.'/'.$order->id, $filename, self::DISK);

        $order->forceFill([
            'payment_proof_path' => $path,
            'payment_proof_mime' => (string) $file->getMimeType(),
            'payment_proof_original_name' => $file->getClientOriginalName(),
        ])->save();
    }

    public static function absolutePath(Order $order): ?string
    {
        if (! self::existsFor($order)) {
            return null;
        }

        return Storage::disk(self::DISK)->path((string) $order->payment_proof_path);
    }
}
