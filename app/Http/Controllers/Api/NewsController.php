<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Support\NewsImageStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class NewsController extends Controller
{
    public function index()
    {
        $rows = News::query()
            ->where('estado', 'activo')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (News $n) => $n->toPublicArray());

        return response()->json(['data' => $rows]);
    }

    public function image(News $news): Response
    {
        if ($news->estado !== 'activo' || ! NewsImageStorage::existsFor($news)) {
            abort(404);
        }

        return Storage::disk(NewsImageStorage::DISK)->response(
            (string) $news->image_path,
            $news->image_original_name ?: 'noticia-'.$news->id,
            ['Content-Type' => $news->image_mime ?: 'image/jpeg'],
        );
    }
}
