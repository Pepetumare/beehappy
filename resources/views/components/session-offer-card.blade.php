@props(['offer'])
<article class="bee-card flex min-w-0 flex-col">
    <p class="bee-kicker">{{ $offer->type->label() }}</p>
    <h3 class="mt-3 break-words text-xl font-bold">{{ $offer->name }}</h3>
    @if($offer->description)<p class="mt-3 whitespace-pre-line break-words text-sm leading-6 text-gray-600">{{ $offer->description }}</p>@endif
    <p class="mt-4 text-sm">{{ $offer->sessions_count }} {{ $offer->sessions_count === 1 ? 'sesión' : 'sesiones' }} · {{ config('appointments.session_minutes') }} minutos por sesión</p>
    <p class="mb-5 mt-3 text-2xl font-bold"><x-clp :amount="$offer->price_clp" /></p>
    <a href="{{ route('purchases.review', $offer) }}" class="bee-button mt-auto" aria-label="Revisar solicitud de {{ $offer->name }}">Revisar solicitud</a>
</article>
