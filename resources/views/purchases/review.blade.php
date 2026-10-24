<x-app-layout>
    <a href="{{ route('therapists.show', $offer->therapistProfile) }}" class="text-sm font-bold text-[#397442]">← Volver al profesional</a>
    <h1 class="mb-6 mt-4 font-serif text-3xl">Revisa tu solicitud de compra</h1>
    <x-availability-feedback />
    <section class="bee-card max-w-2xl">
        <p class="bee-kicker">{{ $offer->type->label() }}</p>
        <h2 class="mt-3 break-words text-2xl font-bold">{{ $offer->name }}</h2>
        <p class="mt-3 whitespace-pre-line break-words text-sm text-gray-600">{{ $offer->description }}</p>
        <dl class="mt-5 grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-600">Profesional</dt><dd class="break-words font-bold">{{ $offer->therapistProfile->user->name }}</dd></div>
            <div><dt class="text-sm text-gray-600">Sesiones</dt><dd class="font-bold">{{ $offer->sessions_count }}</dd></div>
            <div><dt class="text-sm text-gray-600">Precio total</dt><dd class="text-xl font-bold"><x-clp :amount="$offer->price_clp" /></dd></div>
            <div><dt class="text-sm text-gray-600">Duración de cada sesión</dt><dd>{{ config('appointments.session_minutes') }} minutos</dd></div>
        </dl>
        <p class="mt-6 rounded-xl bg-[#fff5cc] p-4 text-sm font-bold">Estas sesiones son válidas únicamente con {{ $offer->therapistProfile->user->name }}.</p>
        <p class="mt-4 text-sm leading-6 text-gray-600">Al confirmar, la solicitud quedará Pendiente de pago. Todavía no se acreditan sesiones, no se reserva un horario y no se realiza ningún cobro.</p>
        <form method="POST" action="{{ route('purchases.store') }}" class="mt-6">@csrf<input type="hidden" name="session_offer_id" value="{{ $offer->id }}"><input type="hidden" name="idempotency_key" value="{{ $key }}"><x-primary-button>Confirmar solicitud</x-primary-button></form>
    </section>
</x-app-layout>
