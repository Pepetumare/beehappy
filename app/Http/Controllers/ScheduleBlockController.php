<?php

namespace App\Http\Controllers;

use App\Services\AvailabilityService;
use App\Services\TherapistCalendarService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ScheduleBlockController extends Controller
{
    public function index(Request $request)
    {
        $profile = $request->user()->therapistProfile()->firstOrCreate();

        return view('therapist.blocks', ['blocks' => $profile->blocks()->orderByDesc('starts_at')->paginate(15)]);
    }

    public function store(Request $request, AvailabilityService $availability, TherapistCalendarService $calendar)
    {
        $data = $request->validate([
            'date' => ['required', 'string'],
            'all_day' => ['required', 'boolean'],
            'start_time' => ['exclude_if:all_day,1', 'required', 'date_format:H:i'],
            'end_time' => ['exclude_if:all_day,1', 'required', 'date_format:H:i', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'start_time.required' => 'Indica la hora de inicio del bloqueo.',
            'end_time.required' => 'Indica la hora de término del bloqueo.',
            'end_time.after' => 'El término debe ser posterior al inicio, dentro del mismo día.',
            '*.date_format' => 'Usa una hora válida con formato HH:MM.',
            'reason.max' => 'El motivo puede tener hasta 500 caracteres.',
        ]);
        $day = $availability->localDate($data['date'], false);
        if ($request->boolean('all_day')) {
            $start = $day;
            $end = $availability->localDate($day->addDay()->toDateString(), false);
        } else {
            $start = $availability->localTime($data['date'], $data['start_time']);
            $end = $availability->localTime($data['date'], $data['end_time'], true);
            if (! $start || ! $end || $start->gte($end)) {
                throw ValidationException::withMessages(['start_time' => 'El intervalo no existe en la hora local de Santiago.']);
            }
        }
        $calendar->change($request->user(), fn ($profile) => $profile->blocks()->create([
            'starts_at' => $start->utc()->format('Y-m-d H:i:s'),
            'ends_at' => $end->utc()->format('Y-m-d H:i:s'),
            'reason' => $data['reason'] ?? null,
        ]));

        return back()->with('success', 'Bloqueo creado.');
    }

    public function destroy(Request $request, string $block, TherapistCalendarService $calendar)
    {
        $calendar->change($request->user(), fn ($profile) => $profile->blocks()->findOrFail($block)->delete());

        return back()->with('success', 'Bloqueo eliminado.');
    }
}
