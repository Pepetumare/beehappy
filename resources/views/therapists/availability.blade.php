<x-app-layout>
    <a href="{{ route('therapists.index') }}" class="text-sm font-semibold text-[#397442]">← Volver a apiterapeutas</a>
    <section class="bee-card mt-5">
        <p class="bee-kicker">Consulta de disponibilidad</p>
        <h1 class="mt-3 break-words font-serif text-3xl">{{ $therapist->user->name }}</h1>
        <p class="mt-2 text-sm text-[#63815c]">{{ $therapist->commune ?: 'Comuna por informar' }}</p>
        <p class="mt-4 whitespace-pre-line break-words text-sm leading-6 text-gray-600">{{ $therapist->introduction }}</p>
    </section>
    <section class="mt-6" aria-labelledby="offers-heading">
        <h2 id="offers-heading" class="mb-4 text-xl font-bold">Sesiones y packs</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($offers as $offer)<x-session-offer-card :offer="$offer" />@empty<p class="bee-card sm:col-span-2 lg:col-span-3">Este profesional aún no tiene ofertas disponibles.</p>@endforelse
        </div>
        <div class="mt-4">{{ $offers->links() }}</div>
    </section>
    <section class="bee-card mt-6">
        <h2 class="text-lg font-bold">Elige una fecha</h2>
        <x-santiago-time-note class="mt-2" />
        <div class="mt-4"><x-availability-feedback /></div>
        <form method="GET" action="{{ route('therapists.show', $therapist) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="min-w-0"><x-input-label for="date" value="Fecha de atención" /><x-text-input id="date" name="date" type="date" :value="$date" :min="$minDate" :max="$maxDate" class="mt-1 w-full min-w-0" required /></div>
            <x-primary-button>Consultar horarios</x-primary-button>
        </form>
        <h3 class="mb-4 mt-7 font-semibold">Horarios del {{ \Carbon\CarbonImmutable::parse($date)->format('d/m/Y') }}</h3>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-live="polite">
            @forelse ($slots as $slot)
                <a href="{{ route('appointments.review', ['therapist_id' => $therapist->id, 'date' => $date, 'starts_at' => $slot['starts_at']]) }}" class="rounded-xl border border-[#dce8cd] bg-[#f4f8ee] px-4 py-3 text-center text-sm font-semibold hover:bg-[#fff2b8]" aria-label="Revisar reserva para {{ $slot['label'] }}">{{ $slot['label'] }}</a>
            @empty
                <p class="rounded-xl bg-[#f8f9f4] p-4 text-sm sm:col-span-2 lg:col-span-3">No hay horarios disponibles</p>
            @endforelse
        </div>
        <p class="mt-6 text-sm text-gray-600">Esta consulta es informativa. No crea una reserva.</p>
    </section>
</x-app-layout>
