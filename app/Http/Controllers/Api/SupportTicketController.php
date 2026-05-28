<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $rows = SupportTicket::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['items' => $rows]);
    }

    public function store(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'subject' => ['required', 'string', 'min:4', 'max:160'],
            'category' => ['nullable', 'string', 'max:32'],
            'priority' => ['nullable', 'string', 'max:16'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $code = 'SUP-'.strtoupper(Str::random(6));
        // En caso extremo de colisión
        while (SupportTicket::query()->where('code', $code)->exists()) {
            $code = 'SUP-'.strtoupper(Str::random(6));
        }

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'code' => $code,
            'subject' => $validated['subject'],
            'category' => $validated['category'] ?? 'OTRO',
            'priority' => $validated['priority'] ?? 'Media',
            'message' => $validated['message'],
            'status' => 'Abierto',
        ]);

        return response()->json([
            'message' => 'Ticket creado.',
            'ticket' => $ticket,
        ], 201);
    }
}

