<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSessionOfferRequest;
use App\Models\SessionOffer;
use App\Models\TherapistProfile;
use App\Models\User;
use App\Services\SessionOfferService;
use Illuminate\Http\Request;

class SessionOfferController extends Controller
{
    public function index(Request $request)
    {
        return view('offers.index', ['offers' => SessionOffer::manageableBy($request->user())->with('therapistProfile.user:id,name')->orderByDesc('id')->paginate(12)]);
    }

    private function form(Request $request, ?SessionOffer $offer = null)
    {
        return view('offers.form', [
            'offer' => $offer,
            'therapists' => $request->user()->isAdministrator()
                ? TherapistProfile::whereHas('user', fn ($q) => $q->where('role', User::ROLE_THERAPIST))->with('user:id,name')->orderBy('id')->get() : collect(),
        ]);
    }

    public function create(Request $request)
    {
        return $this->form($request);
    }

    public function edit(Request $request, int $offer)
    {
        return $this->form($request, SessionOffer::manageableBy($request->user())->findOrFail($offer));
    }

    public function store(SaveSessionOfferRequest $request, SessionOfferService $service)
    {
        $offer = $service->save($request->user(), $request->validated());

        return redirect()->route('offers.edit', $offer)->with('success', 'Oferta creada.');
    }

    public function update(SaveSessionOfferRequest $request, int $offer, SessionOfferService $service)
    {
        $service->save($request->user(), $request->validated(), $offer);

        return redirect()->route('offers.edit', $offer)->with('success', 'Oferta actualizada. Las compras anteriores conservan sus condiciones.');
    }

    public function toggle(Request $request, int $offer, SessionOfferService $service)
    {
        $request->validate(['active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $offer, $request->boolean('active'));

        return back()->with('success', 'Disponibilidad de la oferta actualizada.');
    }
}
