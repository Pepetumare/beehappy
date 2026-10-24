<x-app-layout>
    <a href="{{ auth()->user()->isPatient() ? route('appointments.index') : route('appointments.agenda', ['date' => $appointment->starts_at->setTimezone(config('appointments.timezone'))->toDateString()]) }}" class="text-sm font-bold text-[#397442]">← Volver a mis citas</a>
    <h1 class="mb-6 mt-4 font-serif text-3xl">Detalle de la cita</h1>
    <x-availability-feedback />
    <section class="bee-card max-w-3xl">
        <span class="rounded-full bg-[#fff4c8] px-3 py-1 text-sm font-bold">{{ $appointment->status->label() }}</span>
        <dl class="mt-6 grid gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-600">Apiterapeuta</dt><dd class="break-words font-bold">{{ $appointment->therapistProfile->user->name }}</dd></div>
            <div><dt class="text-sm text-gray-600">Paciente</dt><dd class="break-words font-bold">{{ $appointment->patientProfile->user->name }}</dd></div>
            <div><dt class="text-sm text-gray-600">Inicio</dt><dd class="font-bold">{{ $appointment->starts_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i') }}</dd></div>
            <div><dt class="text-sm text-gray-600">Término</dt><dd class="font-bold">{{ $appointment->ends_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i') }}</dd></div>
            <div><dt class="text-sm text-gray-600">Duración al reservar</dt><dd>{{ $appointment->duration_minutes }} minutos</dd></div>
        </dl>
        <x-santiago-time-note class="mt-4" />
        <p class="mt-6 text-sm text-gray-600">Una cita agendada no implica un pago aprobado.</p>
        @if ($appointment->cancelled_at)
            <div class="mt-6 rounded-xl bg-gray-50 p-4 text-sm"><p>Cancelada el {{ $appointment->cancelled_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i') }} por {{ $appointment->canceller?->name ?? 'Usuario no disponible' }}.</p>@if($appointment->cancellation_reason)<p class="mt-2 break-words">{{ $appointment->cancellation_reason }}</p>@endif</div>
        @endif
    </section>
    @if ($appointment->canCancel())
        <section class="bee-card mt-6 max-w-3xl"><h2 class="text-lg font-bold">Cancelar cita</h2><p class="mt-2 text-sm text-gray-600">La cita quedará en el historial y el horario volverá a estar disponible.</p>
            <form method="POST" action="{{ route('appointments.cancel', $appointment) }}" class="mt-4 space-y-4">
                @csrf
                <div><x-input-label for="reason" value="Motivo de cancelación (opcional, visible para ambas partes)" /><textarea name="reason" id="reason" maxlength="500" rows="3" class="mt-1 w-full rounded-xl border-gray-300">{{ old('reason') }}</textarea></div>
                <button class="bee-secondary">Cancelar esta cita</button>
            </form>
        </section>
    @endif
    @if (auth()->user()->isTherapist() && $appointment->canRecordOutcome())
        <section class="bee-card mt-6 max-w-3xl"><h2 class="text-lg font-bold">Registrar asistencia</h2><p class="mt-2 text-sm text-gray-600">La sesión ya terminó. Registra lo ocurrido.</p>
            <form method="POST" action="{{ route('appointments.outcome', $appointment) }}" class="mt-4 flex flex-wrap gap-3">
                @csrf @method('PATCH')
                <button name="outcome" value="{{ \App\Enums\AppointmentStatus::Completed->value }}" class="bee-button">{{ \App\Enums\AppointmentStatus::Completed->label() }}</button>
                <button name="outcome" value="{{ \App\Enums\AppointmentStatus::NoShow->value }}" class="bee-secondary">{{ \App\Enums\AppointmentStatus::NoShow->label() }}</button>
            </form>
        </section>
    @endif
</x-app-layout>
