<x-app-layout>
    <p class="bee-kicker">Mi espacio profesional</p>
    <h1 class="mt-3 font-serif text-3xl">Mi agenda</h1>
    <x-santiago-time-note class="mb-6 mt-3" />
    <x-availability-feedback />
    <form method="GET" action="{{ route('appointments.agenda') }}" class="bee-card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="min-w-0"><x-input-label for="agenda-date" value="Fecha de la agenda" /><x-text-input id="agenda-date" name="date" type="date" :value="$date" class="mt-1 w-full min-w-0" required /></div>
        <x-primary-button>Ver agenda</x-primary-button>
    </form>
    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($appointments as $appointment)
            <x-appointment-card :appointment="$appointment" />
        @empty
            <p class="bee-card md:col-span-2">No hay citas para este día.</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $appointments->links() }}</div>
</x-app-layout>
