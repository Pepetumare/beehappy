<x-app-layout>
    <p class="bee-kicker">Un momento para ti</p>
    <h1 class="mb-6 mt-3 font-serif text-3xl">Revisa tu reserva</h1>
    <x-availability-feedback />
    <section class="bee-card max-w-2xl">
        <h2 class="text-xl font-bold">{{ $therapist->user->name }}</h2>
        <p class="mt-2 text-sm text-gray-600">{{ $therapist->commune ?: 'Comuna por informar' }}</p>
        <dl class="mt-6 grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-600">Fecha</dt><dd class="font-bold">{{ $start->setTimezone(config('appointments.timezone'))->format('d/m/Y') }}</dd></div>
            <div><dt class="text-sm text-gray-600">Horario</dt><dd class="font-bold">{{ $start->setTimezone(config('appointments.timezone'))->format('H:i') }}–{{ $end->setTimezone(config('appointments.timezone'))->format('H:i') }}</dd></div>
            <div><dt class="text-sm text-gray-600">Paciente</dt><dd class="break-words font-bold">{{ auth()->user()->name }}</dd></div>
            <div><dt class="text-sm text-gray-600">Duración</dt><dd class="font-bold">{{ config('appointments.session_minutes') }} minutos</dd></div>
        </dl>
        <x-santiago-time-note class="mt-4" />
        <p class="mt-6 text-sm text-gray-600">El horario se verificará nuevamente al confirmar. Esta reserva no implica un pago aprobado.</p>
        <form method="POST" action="{{ route('appointments.store') }}" class="mt-6">
            @csrf
            <input type="hidden" name="therapist_id" value="{{ $therapist->id }}">
            <input type="hidden" name="date" value="{{ $date }}">
            <input type="hidden" name="starts_at" value="{{ $start->toIso8601String() }}">
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $key) }}">
            <x-primary-button>Confirmar reserva</x-primary-button>
        </form>
        <a href="{{ route('therapists.show', ['therapist' => $therapist, 'date' => $date]) }}" class="bee-secondary mt-3">Actualizar disponibilidad</a>
    </section>
</x-app-layout>
