<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Mail\MemberNotificationService;
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

        return response()->json([
            'items' => $rows->map(fn (SupportTicket $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'subject' => $t->subject,
                'category' => $t->category,
                'priority' => $t->priority,
                'status' => $t->status,
                'created_at' => $t->created_at?->toIso8601String(),
            ]),
        ]);
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

        app(MemberNotificationService::class)->sendSupportTicketStatus($ticket);

        return response()->json([
            'message' => 'Ticket creado.',
            'ticket' => [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'subject' => $ticket->subject,
                'category' => $ticket->category,
                'priority' => $ticket->priority,
                'message' => $ticket->message,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}

