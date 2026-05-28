<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /**
     * GET /api/v1/me/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) min($request->query('per_page', 25), 100);

        $paginator = Invoice::query()
            ->where('user_id', $request->user()->id)
            ->with(['items', 'order:id,tipo,estado,total,created_at'])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => InvoiceResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/me/invoices/{invoice}
     */
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if ((int) $invoice->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $invoice->load(['items', 'order:id,tipo,estado,total,total_pv,created_at']);

        return response()->json([
            'invoice' => new InvoiceResource($invoice),
        ]);
    }
}
