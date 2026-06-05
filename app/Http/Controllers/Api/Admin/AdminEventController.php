<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\EventFlyerStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AdminEventController extends Controller
{
    public function index()
    {
        $rows = Event::query()->orderByDesc('starts_at')->get()
            ->map(function (Event $e) {
                $arr = $e->toArray();
                $arr['has_flyer'] = EventFlyerStorage::existsFor($e);

                return $arr;
            });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedEvent($request);
        $event = Event::query()->create($data);

        if ($request->hasFile('flyer')) {
            EventFlyerStorage::store($event, $request->file('flyer'));
        }

        return response()->json($this->adminPayload($event->fresh()), 201);
    }

    public function update(Request $request, Event $event)
    {
        $data = $this->validatedEvent($request, $event);
        $event->update($data);

        if ($request->hasFile('flyer')) {
            EventFlyerStorage::store($event, $request->file('flyer'));
        }

        return response()->json($this->adminPayload($event->fresh()));
    }

    public function destroy(Event $event)
    {
        $event->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Evento desactivado.']);
    }

    public function flyer(Event $event): Response
    {
        if (! EventFlyerStorage::existsFor($event)) {
            abort(404);
        }

        return Storage::disk(EventFlyerStorage::DISK)->response(
            (string) $event->flyer_path,
            $event->flyer_original_name ?: 'flyer-'.$event->id,
            ['Content-Type' => $event->flyer_mime ?: 'image/jpeg'],
        );
    }

    private function validatedEvent(Request $request, ?Event $existing = null): array
    {
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in([Event::KIND_VIRTUAL, Event::KIND_PRESENCIAL])],
            'platform' => ['nullable', 'string', Rule::in([Event::PLATFORM_YOUTUBE, Event::PLATFORM_ZOOM])],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'speaker' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'virtual_url' => ['nullable', 'string', 'max:2048'],
            'address' => ['nullable', 'string', 'max:500'],
            'entry_cost' => ['nullable', 'numeric', 'min:0'],
            'details' => ['nullable', 'string'],
            'estado' => ['nullable', 'string', Rule::in(['activo', 'inactivo'])],
            'flyer' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        if ($data['kind'] === Event::KIND_VIRTUAL) {
            if (empty($data['platform'])) {
                throw ValidationException::withMessages([
                    'platform' => ['Indica si el evento virtual es YouTube o Zoom.'],
                ]);
            }
            if (empty(trim((string) ($data['virtual_url'] ?? '')))) {
                throw ValidationException::withMessages([
                    'virtual_url' => ['Indica el enlace de YouTube o Zoom.'],
                ]);
            }
            $data['address'] = null;
            $data['entry_cost'] = 0;
        } else {
            $data['platform'] = null;
            $data['virtual_url'] = null;
            if (empty(trim((string) ($data['address'] ?? '')))) {
                throw ValidationException::withMessages([
                    'address' => ['Indica la dirección del evento presencial.'],
                ]);
            }
        }

        $data['estado'] = $data['estado'] ?? 'activo';
        $data['entry_cost'] = $data['entry_cost'] ?? 0;

        return $data;
    }

    private function adminPayload(Event $event): array
    {
        $arr = $event->toArray();
        $arr['has_flyer'] = EventFlyerStorage::existsFor($event);
        $arr['flyer_admin_url'] = EventFlyerStorage::existsFor($event)
            ? ($event->estado === 'activo'
                ? url('/api/v1/public/events/'.$event->id.'/flyer')
                : url('/api/v1/admin/events/'.$event->id.'/flyer'))
            : null;

        return $arr;
    }
}
