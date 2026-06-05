<?php

namespace App\Support;

use App\Models\News;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class NewsImageStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'news-images';

    public static function existsFor(News $news): bool
    {
        $path = (string) ($news->image_path ?? '');

        return $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    public static function store(News $news, UploadedFile $file): void
    {
        if ($news->image_path) {
            Storage::disk(self::DISK)->delete($news->image_path);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::uuid().'.'.$ext;
        $path = $file->storeAs(self::DIRECTORY.'/'.$news->id, $filename, self::DISK);

        $news->forceFill([
            'image_path' => $path,
            'image_mime' => (string) $file->getMimeType(),
            'image_original_name' => $file->getClientOriginalName(),
        ])->save();
    }
}
