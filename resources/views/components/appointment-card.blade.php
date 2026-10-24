@props(['appointment'])
<article class="bee-card min-w-0">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm font-semibold">{{ $appointment->starts_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i') }}–{{ $appointment->ends_at->setTimezone(config('appointments.timezone'))->format('H:i') }}</p>
            <h2 class="mt-2 break-words text-lg font-bold">{{ auth()->user()->isPatient() ? $appointment->therapistProfile->user->name : $appointment->patientProfile->user->name }}</h2>
        </div>
        <span class="rounded-full bg-[#fff4c8] px-3 py-1 text-sm font-semibold">{{ $appointment->status->label() }}</span>
    </div>
    <a href="{{ route('appointments.show', $appointment) }}" class="bee-secondary mt-4" aria-label="Ver detalle de la cita del {{ $appointment->starts_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i') }}">Ver detalle</a>
</article>
