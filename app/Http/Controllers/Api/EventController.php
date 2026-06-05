<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\EventFlyerStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $rows = Event::query()
            ->where('estado', 'activo')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Event $e) => $e->toPublicArray());

        return response()->json(['data' => $rows]);
    }

    public function show(Event $event)
    {
        if ($event->estado !== 'activo') {
            abort(404);
        }

        return response()->json(['data' => $event->toPublicArray()]);
    }

    public function flyer(Event $event): Response
    {
        if ($event->estado !== 'activo' || ! EventFlyerStorage::existsFor($event)) {
            abort(404);
        }

        return Storage::disk(EventFlyerStorage::DISK)->response(
            (string) $event->flyer_path,
            $event->flyer_original_name ?: 'flyer-evento-'.$event->id,
            ['Content-Type' => $event->flyer_mime ?: 'image/jpeg'],
        );
    }
}
