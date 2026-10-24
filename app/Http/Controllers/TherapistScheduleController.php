<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTherapistSchedulesRequest;
use App\Services\TherapistCalendarService;
use Illuminate\Http\Request;

class TherapistScheduleController extends Controller
{
    public function edit(Request $request)
    {
        $profile = $request->user()->therapistProfile()->firstOrCreate();

        return view('therapist.schedules', ['profile' => $profile, 'schedules' => $profile->schedules()->orderBy('day_of_week')->orderBy('start_time')->get()]);
    }

    public function update(UpdateTherapistSchedulesRequest $request, TherapistCalendarService $calendar)
    {
        $calendar->change($request->user(), function ($profile) use ($request) {
            $profile->schedules()->delete();
            $profile->schedules()->createMany($request->validated('schedules'));
        });

        return back()->with('success', 'Horarios semanales guardados.');
    }

    public function updateProfessionalProfile(Request $request, TherapistCalendarService $calendar)
    {
        $data = $request->validate([
            'commune' => ['nullable', 'string', 'max:120'],
            'introduction' => ['nullable', 'string', 'max:2000'],
            'accepts_appointments' => ['required', 'boolean'],
        ]);
        $calendar->change($request->user(), fn ($profile) => $profile->update($data));

        return back()->with('success', 'Presentación profesional actualizada.');
    }
}
