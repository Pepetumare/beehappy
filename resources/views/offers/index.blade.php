<x-app-layout>
    <p class="bee-kicker">{{ auth()->user()->isAdministrator() ? 'Administración' : 'Mi espacio profesional' }}</p>
    <h1 class="mt-3 font-serif text-3xl">Sesiones y packs</h1>
    <p class="mb-6 mt-3 text-sm text-gray-600">Ofertas para sesiones de {{ config('appointments.session_minutes') }} minutos. Cada oferta pertenece a un apiterapeuta.</p>
    <x-availability-feedback />
    <div class="mb-6 flex flex-wrap gap-3"><a href="{{ route('offers.create') }}" class="bee-button">Crear oferta</a><a href="{{ route('purchases.index') }}" class="bee-secondary">Solicitudes de compra</a></div>
    <div class="grid gap-5 md:grid-cols-2">
        @forelse ($offers as $offer)
            <article class="bee-card min-w-0">
                <div class="flex flex-wrap justify-between gap-3"><p class="bee-kicker">{{ $offer->type->label() }}</p><span class="rounded-full bg-[#eef3e6] px-3 py-1 text-xs font-bold">{{ $offer->active ? 'Activa' : 'Inactiva' }}</span></div>
                <h2 class="mt-3 break-words text-xl font-bold">{{ $offer->name }}</h2>
                <p class="mt-2 break-words text-sm text-gray-600">{{ $offer->therapistProfile->user->name }}</p>
                <p class="mt-3 text-sm">{{ $offer->sessions_count }} {{ $offer->sessions_count === 1 ? 'sesión' : 'sesiones' }}</p>
                <p class="mt-2 text-xl font-bold"><x-clp :amount="$offer->price_clp" /></p>
                <div class="mt-5 flex flex-wrap gap-3"><a href="{{ route('offers.edit', $offer) }}" class="bee-secondary" aria-label="Editar {{ $offer->name }}">Editar</a>
                    <form method="POST" action="{{ route('offers.toggle', $offer) }}">@csrf @method('PATCH')<input type="hidden" name="active" value="{{ $offer->active ? 0 : 1 }}"><button class="bee-secondary" aria-label="{{ $offer->active ? 'Desactivar' : 'Activar' }} {{ $offer->name }}">{{ $offer->active ? 'Desactivar' : 'Activar' }}</button></form>
                </div>
            </article>
        @empty
            <p class="bee-card md:col-span-2">Aún no hay ofertas. Crea una sesión individual o un pack para comenzar.</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $offers->links() }}</div>
</x-app-layout>
