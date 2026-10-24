<x-app-layout>
    <a href="{{ route('purchases.index') }}" class="text-sm font-bold text-[#397442]">← Volver a compras</a>
    <h1 class="mb-6 mt-4 font-serif text-3xl">Detalle de la solicitud</h1>
    <x-availability-feedback />
    <section class="bee-card max-w-3xl">
        <p class="bee-kicker">{{ $purchase->status->label() }}</p><h2 class="mt-3 break-words text-2xl font-bold">{{ $purchase->offer_name }}</h2>
        <p class="mt-3 break-all text-xs text-gray-600">Referencia: {{ $purchase->reference }}</p>
        <dl class="mt-6 grid gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-600">Profesional vendedor</dt><dd class="break-words font-bold">{{ $purchase->therapistProfile->user->name }}</dd></div>
            <div><dt class="text-sm text-gray-600">Paciente</dt><dd class="break-words font-bold">{{ $purchase->patientProfile->user->name }}</dd></div>
            <div><dt class="text-sm text-gray-600">Oferta solicitada</dt><dd>{{ $purchase->offer_type->label() }} · {{ $purchase->sessions_count }} {{ $purchase->sessions_count === 1 ? 'sesión' : 'sesiones' }}</dd></div>
            <div><dt class="text-sm text-gray-600">Precio total acordado</dt><dd class="text-xl font-bold"><x-clp :amount="$purchase->total_clp" /></dd></div>
            <div><dt class="text-sm text-gray-600">Fecha de solicitud</dt><dd>{{ $purchase->created_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i') }}</dd></div>
        </dl>
        <x-santiago-time-note class="mt-4" />
        <p class="mt-5 rounded-xl bg-[#fff5cc] p-4 text-sm font-bold">Estas sesiones son válidas únicamente con {{ $purchase->therapistProfile->user->name }}.</p>
        @if($purchase->status === \App\Enums\PurchaseStatus::PendingPayment)
            <p class="mt-4 text-sm text-gray-600">Pendiente de pago. Aún no se han acreditado sesiones. Esta solicitud no crea reservas ni ocupa horarios.</p>
            @if(auth()->user()->isPatient())<form method="POST" action="{{ route('purchases.cancel', $purchase) }}" class="mt-5">@csrf<button class="bee-secondary">Cancelar solicitud</button></form>@endif
        @else
            <p class="mt-4 text-sm text-gray-600">Solicitud cancelada el {{ $purchase->cancelled_at?->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i') }}. No se han acreditado sesiones.</p>
        @endif
    </section>
</x-app-layout>
