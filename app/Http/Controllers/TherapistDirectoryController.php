<?php

namespace App\Http\Controllers;

use App\Models\TherapistProfile;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class TherapistDirectoryController extends Controller
{
    public function index()
    {
        return view('therapists.index', [
            'therapists' => TherapistProfile::bookable()->with('user:id,name')->orderBy('id')->paginate(12),
        ]);
    }

    public function show(Request $request, string $therapist, AvailabilityService $availability)
    {
        $profile = TherapistProfile::bookable()->with('user:id,name')->findOrFail($therapist);
        $data = $request->validate(['date' => ['sometimes', 'required', 'string']]);
        $date = $data['date'] ?? $availability->today()->toDateString();

        return view('therapists.availability', [
            'therapist' => $profile, 'date' => $date,
            'offers' => $profile->sessionOffers()->available()->orderBy('sessions_count')->orderBy('id')->paginate(6, ['*'], 'offers_page')->withQueryString(),
            'slots' => $availability->forDate($profile, $date, false, $request->user()->patientProfile),
            'minDate' => $availability->today()->toDateString(),
            'maxDate' => $availability->lastDate()->toDateString(),
        ]);
    }
}
