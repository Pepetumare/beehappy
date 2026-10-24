<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user) {
            // Same stable profile order as booking: patient, then therapist, then user.
            $patient = $user->patientProfile()->lockForUpdate()->first();
            $therapist = $user->therapistProfile()->lockForUpdate()->first();
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (($patient && $patient->appointments()->lockForUpdate()->first())
                || ($therapist && $therapist->appointments()->lockForUpdate()->first())) {
                throw ValidationException::withMessages(['account' => 'Tu cuenta tiene historial de citas y no puede eliminarse desde este formulario. El historial se conserva.'])->errorBag('userDeletion');
            }
            if (($patient && $patient->purchases()->lockForUpdate()->first())
                || ($therapist && ($therapist->purchases()->lockForUpdate()->first() || $therapist->sessionOffers()->lockForUpdate()->first()))) {
                throw ValidationException::withMessages(['account' => 'Tu cuenta tiene solicitudes de compra o un catálogo de ofertas y no puede eliminarse desde este formulario. El historial se conserva.'])->errorBag('userDeletion');
            }
            // Logout rotates the remember token; do this before deletion to avoid saving a deleted model.
            Auth::logout();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
