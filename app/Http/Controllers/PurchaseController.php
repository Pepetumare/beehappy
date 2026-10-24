<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\SessionOffer;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        return view('purchases.index', ['purchases' => Purchase::visibleTo($request->user())
            ->with(['therapistProfile.user:id,name', 'patientProfile.user:id,name'])->orderByDesc('id')->paginate(12)]);
    }

    public function review(int $offer)
    {
        return view('purchases.review', ['offer' => SessionOffer::available()->with('therapistProfile.user:id,name')->findOrFail($offer), 'key' => (string) Str::uuid()]);
    }

    public function store(Request $request, PurchaseService $service)
    {
        $data = $request->validate(['session_offer_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid']]);
        try {
            $purchase = $service->create($request->user(), $data['session_offer_id'], $data['idempotency_key']);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return redirect()->route('purchases.index')->withErrors($exception->errors())->withInput();
        }

        return redirect()->route('purchases.show', $purchase)->with('success', 'Solicitud registrada. Aún no se han acreditado sesiones.');
    }

    public function show(Request $request, string $purchase)
    {
        return view('purchases.show', ['purchase' => Purchase::visibleTo($request->user())->where('reference', $purchase)
            ->with(['therapistProfile.user:id,name', 'patientProfile.user:id,name'])->firstOrFail()]);
    }

    public function cancel(Request $request, string $purchase, PurchaseService $service)
    {
        $service->cancel($request->user(), $purchase);

        return redirect()->route('purchases.show', $purchase)->with('success', 'La solicitud está cancelada.');
    }
}
