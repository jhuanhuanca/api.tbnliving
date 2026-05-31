<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ResolvesInternalPanelActor;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\Mail\MemberNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSupportTicketController extends Controller
{
    use ResolvesInternalPanelActor;

    public const STATUSES = ['Abierto', 'En proceso', 'Resuelto', 'Cerrado'];

    public const PRIORITIES = ['Baja', 'Media', 'Alta'];

    public function index(Request $request)
    {
        $estado = (string) $request->query('estado', 'all');
        $prioridad = (string) $request->query('prioridad', 'all');

        $q = SupportTicket::query()
            ->with('user:id,name,email,member_code,referral_code')
            ->orderByDesc('id');

        if ($estado !== 'all' && in_array($estado, self::STATUSES, true)) {
            $q->where('status', $estado);
        }

        if ($prioridad !== 'all' && in_array($prioridad, self::PRIORITIES, true)) {
            $q->where('priority', $prioridad);
        }

        return response()->json(
            $q->paginate((int) min($request->query('per_page', 25), 100))
        );
    }

    public function update(Request $request, SupportTicket $supportTicket, MemberNotificationService $notifications)
    {
        $previousStatus = $supportTicket->status;

        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'priority' => ['nullable', 'string', Rule::in(self::PRIORITIES)],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $supportTicket->status = $data['status'];
        }

        if (array_key_exists('priority', $data) && $data['priority'] !== null) {
            $supportTicket->priority = $data['priority'];
        }

        if (array_key_exists('admin_notes', $data)) {
            $meta = is_array($supportTicket->meta) ? $supportTicket->meta : [];
            $meta['admin_notes'] = $data['admin_notes'];
            $meta['updated_by_admin_id'] = $this->resolveActor($request)->id;
            $meta['updated_by_admin_at'] = now()->toIso8601String();
            $supportTicket->meta = $meta;
        }

        $supportTicket->save();

        if (
            array_key_exists('status', $data)
            && $data['status'] !== null
            && $data['status'] !== $previousStatus
        ) {
            $notifications->sendSupportTicketStatus($supportTicket, $previousStatus);
        }

        $supportTicket->load('user:id,name,email,member_code,referral_code');

        return response()->json($supportTicket);
    }
}
