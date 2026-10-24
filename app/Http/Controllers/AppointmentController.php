<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\TherapistProfile;
use App\Services\AppointmentService;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    private function scoped(Request $request)
    {
        $user = $request->user();

        return Appointment::query()->whereHas($user->isPatient() ? 'patientProfile' : 'therapistProfile', fn ($query) => $query->where('user_id', $user->id))
            ->with(['patientProfile.user:id,name', 'therapistProfile.user:id,name']);
    }

    private function availabilityError(array $data, ValidationException $exception)
    {
        $profile = TherapistProfile::bookable()->find($data['therapist_id']);
        $parameters = $profile ? ['therapist' => $profile->id] : [];
        try {
            app(AvailabilityService::class)->localDate($data['date']);
            if ($profile) {
                $parameters['date'] = $data['date'];
            }
        } catch (ValidationException) { /* Return to a valid availability date instead of a redirect loop. */
        }

        return redirect()->route($profile ? 'therapists.show' : 'therapists.index', $parameters)->withErrors($exception->errors())->withInput();
    }

    public function index(Request $request)
    {
        $data = $request->validate(['tab' => ['sometimes', Rule::in(['upcoming', 'history'])]]);
        $tab = $data['tab'] ?? 'upcoming';
        $query = $this->scoped($request);
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        if ($tab === 'upcoming') {
            $query->where('status', AppointmentStatus::Scheduled)->where('starts_at', '>=', $now)->orderBy('starts_at');
        } else {
            $query->where(fn ($q) => $q->where('status', '!=', AppointmentStatus::Scheduled)->orWhere('starts_at', '<', $now))->orderByDesc('starts_at');
        }

        return view('appointments.index', ['appointments' => $query->paginate(12)->withQueryString(), 'tab' => $tab]);
    }

    public function review(Request $request, AvailabilityService $availability, AppointmentService $booking)
    {
        $data = $request->validate(['therapist_id' => ['required', 'integer'], 'date' => ['required', 'string'], 'starts_at' => ['required', 'string']]);
        $therapist = TherapistProfile::bookable()->with('user:id,name')->findOrFail($data['therapist_id']);
        try {
            $start = $booking->parseStart($data['starts_at']);
            $patient = $request->user()->patientProfile()->firstOrCreate();
            $slots = $availability->forDate($therapist, $data['date'], false, $patient);
            if (! $start->isFuture() || ! collect($slots)->contains(fn ($slot) => CarbonImmutable::parse($slot['starts_at'])->equalTo($start))) {
                throw ValidationException::withMessages(['starts_at' => 'Este horario ya no está disponible. Actualiza la disponibilidad.']);
            }
        } catch (ValidationException $exception) {
            return $this->availabilityError($data, $exception);
        }

        return view('appointments.review', [
            'therapist' => $therapist, 'start' => $start, 'date' => $data['date'],
            'end' => $start->addMinutes(config('appointments.session_minutes')), 'key' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, AppointmentService $booking)
    {
        $data = $request->validate([
            'therapist_id' => ['required', 'integer'], 'date' => ['required', 'string'],
            'starts_at' => ['required', 'string'], 'idempotency_key' => ['required', 'uuid'],
        ]);
        try {
            $appointment = $booking->create($request->user(), $data['therapist_id'], $data['date'], $data['starts_at'], $data['idempotency_key']);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return $this->availabilityError($data, $exception);
        }

        return redirect()->route('appointments.show', $appointment)->with('success', $appointment->status === AppointmentStatus::Cancelled ? 'Esta solicitud corresponde a una cita cancelada. No se ha reactivado.' : 'Tu reserva está registrada. No implica un pago aprobado.');
    }

    public function show(Request $request, string $appointment)
    {
        return view('appointments.show', ['appointment' => $this->scoped($request)->findOrFail($appointment)]);
    }

    public function cancel(Request $request, string $appointment, AppointmentService $booking)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $booking->cancel($request->user(), $this->scoped($request)->findOrFail($appointment), $data['reason'] ?? null);

        return back()->with('success', 'La cita está cancelada.');
    }

    public function outcome(Request $request, string $appointment, AppointmentService $booking)
    {
        $data = $request->validate(['outcome' => ['required', Rule::in([AppointmentStatus::Completed->value, AppointmentStatus::NoShow->value])]]);
        $booking->recordOutcome($request->user(), $this->scoped($request)->findOrFail($appointment), AppointmentStatus::from($data['outcome']));

        return back()->with('success', 'Asistencia registrada.');
    }

    public function agenda(Request $request, AvailabilityService $availability)
    {
        $data = $request->validate(['date' => ['sometimes', 'required', 'string']]);
        $date = $data['date'] ?? $availability->today()->toDateString();
        $day = $availability->localDate($date, false);
        $next = $availability->localDate($day->addDay()->toDateString(), false);
        $appointments = $this->scoped($request)->where('starts_at', '>=', $day->utc()->format('Y-m-d H:i:s'))
            ->where('starts_at', '<', $next->utc()->format('Y-m-d H:i:s'))->orderBy('starts_at')->paginate(20)->withQueryString();

        return view('appointments.agenda', compact('appointments', 'date'));
    }
}
