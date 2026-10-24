<x-app-layout>
    <p class="bee-kicker">Tu bienestar, más cerca</p>
    <h1 class="mt-3 font-serif text-3xl">Apiterapeutas</h1>
    <p class="mb-6 mt-3 text-sm text-gray-600">Conoce a los profesionales y consulta sus horarios de atención.</p>
    <x-availability-feedback />
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($therapists as $therapist)
            <article class="bee-card flex min-w-0 flex-col">
                <span class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-[#fff2b8] text-lg font-bold" aria-hidden="true">{{ mb_substr($therapist->user->name, 0, 1) }}</span>
                <h2 class="break-words text-lg font-bold">{{ $therapist->user->name }}</h2>
                <p class="mt-2 text-sm text-[#63815c]">{{ $therapist->commune ?: 'Comuna por informar' }}</p>
                <p class="mb-5 mt-3 whitespace-pre-line break-words text-sm leading-6 text-gray-600">{{ $therapist->introduction ?: 'Este profesional aún no ha añadido una presentación.' }}</p>
                <a href="{{ route('therapists.show', $therapist) }}" class="bee-button mt-auto" aria-label="Ver disponibilidad de {{ $therapist->user->name }}">Ver disponibilidad</a>
            </article>
        @empty
            <p class="bee-card sm:col-span-2 lg:col-span-3">Por ahora no hay apiterapeutas disponibles para recibir citas.</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $therapists->links() }}</div>
</x-app-layout>
