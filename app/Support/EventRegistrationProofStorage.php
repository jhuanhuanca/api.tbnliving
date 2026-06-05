<?php

namespace App\Support;

use App\Models\EventRegistration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class EventRegistrationProofStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'event-registration-proofs';

    public static function existsFor(EventRegistration $registration): bool
    {
        $path = (string) ($registration->payment_proof_path ?? '');

        return $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    public static function store(EventRegistration $registration, UploadedFile $file): void
    {
        if ($registration->payment_proof_path) {
            Storage::disk(self::DISK)->delete($registration->payment_proof_path);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::uuid().'.'.$ext;
        $path = $file->storeAs(self::DIRECTORY.'/'.$registration->id, $filename, self::DISK);

        $registration->forceFill([
            'payment_proof_path' => $path,
            'payment_proof_mime' => (string) $file->getMimeType(),
            'payment_proof_original_name' => $file->getClientOriginalName(),
        ])->save();
    }
}
