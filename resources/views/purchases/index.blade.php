<x-app-layout>
    <p class="bee-kicker">{{ auth()->user()->isPatient() ? 'Mi bienestar' : 'Sesiones y packs' }}</p>
    <h1 class="mb-6 mt-3 font-serif text-3xl">{{ auth()->user()->isPatient() ? 'Mis compras' : 'Solicitudes de compra' }}</h1>
    <x-availability-feedback />
    <p class="mb-5 text-sm text-gray-600">Las solicitudes pendientes de pago todavía no acreditan sesiones.</p>
    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($purchases as $purchase)
            <article class="bee-card min-w-0"><p class="bee-kicker">{{ $purchase->status->label() }}</p><h2 class="mt-3 break-words text-xl font-bold">{{ $purchase->offer_name }}</h2>
                <p class="mt-2 break-words text-sm text-gray-600">{{ $purchase->therapistProfile->user->name }}</p>
                @if(!auth()->user()->isPatient())<p class="mt-1 break-words text-sm">Paciente: {{ $purchase->patientProfile->user->name }}</p>@endif
                <p class="mt-3 text-sm">{{ $purchase->sessions_count }} {{ $purchase->sessions_count === 1 ? 'sesión' : 'sesiones' }}</p><p class="mt-2 text-xl font-bold"><x-clp :amount="$purchase->total_clp" /></p>
                <a href="{{ route('purchases.show', $purchase) }}" class="bee-secondary mt-4" aria-label="Ver compra {{ $purchase->reference }}">Ver detalle</a>
            </article>
        @empty
            <div class="bee-card md:col-span-2"><p>Aún no hay solicitudes de compra.</p>@if(auth()->user()->isPatient())<a href="{{ route('therapists.index') }}" class="bee-button mt-4">Ver apiterapeutas</a>@endif</div>
        @endforelse
    </div>
    <div class="mt-6">{{ $purchases->links() }}</div>
</x-app-layout>
