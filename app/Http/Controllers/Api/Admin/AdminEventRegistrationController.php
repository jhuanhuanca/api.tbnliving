<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ResolvesInternalPanelActor;
use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Support\EventRegistrationProofStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AdminEventRegistrationController extends Controller
{
    use ResolvesInternalPanelActor;

    public function index(Request $request)
    {
        $estado = $request->query('estado', 'pendiente_pago');

        $q = EventRegistration::query()
            ->with([
                'user:id,name,email,member_code',
                'event:id,name,kind,starts_at,entry_cost',
            ]);

        if (in_array($estado, ['pendiente_pago', 'completado', 'cancelado'], true)) {
            $q->where('estado', $estado);
        }

        $paginator = $q->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        $paginator->getCollection()->transform(function (EventRegistration $row) {
            $row->setAttribute('has_payment_proof', EventRegistrationProofStorage::existsFor($row));

            return $row;
        });

        return $paginator;
    }

    public function confirmPayment(Request $request, EventRegistration $registration)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:32'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($registration->estado !== 'pendiente_pago') {
            return response()->json(['message' => 'La inscripción no está pendiente de pago.'], 422);
        }

        $registration->forceFill([
            'payment_method' => $data['payment_method'] ?? $registration->payment_method,
            'payment_admin_notes' => $data['notas'] ?? $registration->payment_admin_notes,
        ]);

        $registration->markCompleted($this->resolveActor($request)->id, $data['notas'] ?? null);

        return response()->json($registration->fresh(['user', 'event']));
    }

    public function paymentProof(EventRegistration $registration): Response
    {
        if (! EventRegistrationProofStorage::existsFor($registration)) {
            abort(404);
        }

        return Storage::disk(EventRegistrationProofStorage::DISK)->response(
            (string) $registration->payment_proof_path,
            $registration->payment_proof_original_name ?: 'comprobante-inscripcion-'.$registration->id,
            ['Content-Type' => $registration->payment_proof_mime ?: 'application/octet-stream'],
        );
    }
}
