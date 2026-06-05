<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Support\EventRegistrationProofStorage;
use App\Support\OrderPaymentProofStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventRegistrationController extends Controller
{
    public function index(Request $request)
    {
        $rows = EventRegistration::query()
            ->where('user_id', $request->user()->id)
            ->with('event:id,name,kind,starts_at,ends_at,platform,virtual_url,address')
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json($rows);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:20'],
            'payment_settlement' => ['nullable', 'string', 'in:immediate,manual'],
            'payment_method' => ['nullable', 'string', 'max:32'],
            'payment_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:5120'],
        ]);

        $event = Event::query()->where('id', $data['event_id'])->where('estado', 'activo')->first();
        if (! $event) {
            throw ValidationException::withMessages([
                'event_id' => ['El evento no está disponible.'],
            ]);
        }

        $qty = (int) ($data['cantidad'] ?? 1);
        $unit = $event->requiresPaidEntry() ? (string) $event->entry_cost : '0';
        $total = bcmul($unit, (string) $qty, 2);

        $immediate = ($data['payment_settlement'] ?? ($event->requiresPaidEntry() ? 'manual' : 'immediate')) === 'immediate';
        $paymentMethod = (string) ($data['payment_method'] ?? ($event->requiresPaidEntry() ? 'transferencia' : 'gratis'));

        if ($event->requiresPaidEntry() && OrderPaymentProofStorage::requiresProof($paymentMethod) && ! $request->hasFile('payment_proof')) {
            throw ValidationException::withMessages([
                'payment_proof' => ['Debes adjuntar el comprobante de pago (imagen o PDF) para transferencia o QR.'],
            ]);
        }

        $registration = DB::transaction(function () use ($request, $event, $qty, $unit, $total, $immediate, $paymentMethod) {
            $row = EventRegistration::query()->create([
                'user_id' => $request->user()->id,
                'event_id' => $event->id,
                'cantidad' => $qty,
                'unit_price' => $unit,
                'total' => $total,
                'estado' => ($immediate && ! $event->requiresPaidEntry()) || ($immediate && $paymentMethod === 'wallet')
                    ? 'completado'
                    : ($event->requiresPaidEntry() ? 'pendiente_pago' : 'completado'),
                'payment_method' => $paymentMethod,
            ]);

            if ($row->estado === 'completado') {
                $row->forceFill(['payment_confirmed_at' => now()])->save();
            }

            return $row;
        });

        if ($request->hasFile('payment_proof')) {
            EventRegistrationProofStorage::store($registration, $request->file('payment_proof'));
            $registration = $registration->fresh();
        }

        $registration->load('event');

        return response()->json([
            'message' => $registration->estado === 'pendiente_pago'
                ? 'Inscripción registrada. Pendiente de confirmación de pago por administración.'
                : 'Inscripción confirmada.',
            'registration' => $registration,
        ], 201);
    }
}
