<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Support\NewsImageStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdminNewsController extends Controller
{
    public function index()
    {
        $rows = News::query()->orderByDesc('published_at')->orderByDesc('id')->get()
            ->map(function (News $n) {
                $arr = $n->toArray();
                $arr['has_image'] = NewsImageStorage::existsFor($n);

                return $arr;
            });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string'],
            'estado' => ['nullable', 'string', Rule::in(['activo', 'inactivo'])],
            'published_at' => ['nullable', 'date'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $data['estado'] = $data['estado'] ?? 'activo';
        $data['published_at'] = $data['published_at'] ?? now();

        $news = News::query()->create($data);

        if ($request->hasFile('image')) {
            NewsImageStorage::store($news, $request->file('image'));
        }

        return response()->json($this->adminPayload($news->fresh()), 201);
    }

    public function update(Request $request, News $news)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string'],
            'estado' => ['sometimes', 'string', Rule::in(['activo', 'inactivo'])],
            'published_at' => ['nullable', 'date'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $news->update($data);

        if ($request->hasFile('image')) {
            NewsImageStorage::store($news, $request->file('image'));
        }

        return response()->json($this->adminPayload($news->fresh()));
    }

    public function destroy(News $news)
    {
        $news->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Noticia desactivada.']);
    }

    public function image(News $news): Response
    {
        if (! NewsImageStorage::existsFor($news)) {
            abort(404);
        }

        return Storage::disk(NewsImageStorage::DISK)->response(
            (string) $news->image_path,
            $news->image_original_name ?: 'noticia-'.$news->id,
            ['Content-Type' => $news->image_mime ?: 'image/jpeg'],
        );
    }

    private function adminPayload(News $news): array
    {
        $arr = $news->toArray();
        $arr['has_image'] = NewsImageStorage::existsFor($news);
        $arr['image_admin_url'] = NewsImageStorage::existsFor($news)
            ? url('/api/v1/admin/news/'.$news->id.'/image')
            : null;

        return $arr;
    }
}
