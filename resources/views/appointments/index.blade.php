<x-app-layout>
    <p class="bee-kicker">Mi bienestar</p>
    <h1 class="mb-6 mt-3 font-serif text-3xl">Mis citas</h1>
    <x-availability-feedback />
    <nav class="mb-6 flex flex-wrap gap-3" aria-label="Filtrar mis citas">
        <a href="{{ route('appointments.index') }}" class="{{ $tab === 'upcoming' ? 'bee-button' : 'bee-secondary' }}" @if($tab === 'upcoming') aria-current="page" @endif>Próximas citas</a>
        <a href="{{ route('appointments.index', ['tab' => 'history']) }}" class="{{ $tab === 'history' ? 'bee-button' : 'bee-secondary' }}" @if($tab === 'history') aria-current="page" @endif>Historial</a>
    </nav>
    <x-santiago-time-note class="mb-4" />
    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($appointments as $appointment)
            <x-appointment-card :appointment="$appointment" />
        @empty
            <div class="bee-card md:col-span-2"><p>{{ $tab === 'upcoming' ? 'No tienes citas próximas.' : 'Aún no hay citas en tu historial.' }}</p><a class="bee-button mt-4" href="{{ route('therapists.index') }}">Ver apiterapeutas</a></div>
        @endforelse
    </div>
    <div class="mt-6">{{ $appointments->links() }}</div>
</x-app-layout>
