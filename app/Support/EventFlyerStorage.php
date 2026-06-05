<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class EventFlyerStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'event-flyers';

    public static function existsFor(Event $event): bool
    {
        $path = (string) ($event->flyer_path ?? '');

        return $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    public static function store(Event $event, UploadedFile $file): void
    {
        if ($event->flyer_path) {
            Storage::disk(self::DISK)->delete($event->flyer_path);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::uuid().'.'.$ext;
        $path = $file->storeAs(self::DIRECTORY.'/'.$event->id, $filename, self::DISK);

        $event->forceFill([
            'flyer_path' => $path,
            'flyer_mime' => (string) $file->getMimeType(),
            'flyer_original_name' => $file->getClientOriginalName(),
        ])->save();
    }
}
