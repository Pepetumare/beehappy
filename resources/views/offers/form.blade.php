<x-app-layout>
    <a href="{{ route('offers.index') }}" class="text-sm font-bold text-[#397442]">← Volver a sesiones y packs</a>
    <h1 class="mb-6 mt-4 font-serif text-3xl">{{ $offer ? 'Editar oferta' : 'Crear oferta' }}</h1>
    <x-availability-feedback />
    <form method="POST" action="{{ $offer ? route('offers.update', $offer) : route('offers.store') }}" class="bee-card max-w-2xl space-y-5" x-data="{ type: @js(old('type', $offer?->type->value ?? \App\Enums\SessionOfferType::Single->value)) }">
        @csrf @if($offer) @method('PUT') @endif
        @if (auth()->user()->isAdministrator() && ! $offer)
            <div><x-input-label for="therapist_profile_id" value="Apiterapeuta vendedor" /><select id="therapist_profile_id" name="therapist_profile_id" required class="mt-1 w-full rounded-xl border-gray-300"><option value="">Selecciona un profesional</option>@foreach($therapists as $therapist)<option value="{{ $therapist->id }}" @selected((string) old('therapist_profile_id') === (string) $therapist->id)>{{ $therapist->user->name }}</option>@endforeach</select></div>
        @endif
        @if ($offer)<p class="text-sm text-gray-600">Vendedor: {{ $offer->therapistProfile->user->name }}. Las compras anteriores conservan sus condiciones.</p>@endif
        <div><x-input-label for="name" value="Nombre" /><x-text-input id="name" name="name" :value="old('name', $offer?->name)" class="mt-1 w-full" maxlength="150" required /></div>
        <div><x-input-label for="description" value="Descripción (opcional)" /><textarea id="description" name="description" maxlength="3000" rows="4" class="mt-1 w-full rounded-xl border-gray-300">{{ old('description', $offer?->description) }}</textarea></div>
        <div><x-input-label for="type" value="Tipo de oferta" /><select id="type" name="type" x-model="type" class="mt-1 w-full rounded-xl border-gray-300" required>@foreach(\App\Enums\SessionOfferType::cases() as $type)<option value="{{ $type->value }}" @selected(old('type', $offer?->type->value ?? \App\Enums\SessionOfferType::Single->value) === $type->value)>{{ $type->label() }}</option>@endforeach</select></div>
        <div><x-input-label for="sessions_count" value="Cantidad de sesiones" /><x-text-input id="sessions_count" name="sessions_count" type="number" min="1" max="100" step="1" :value="old('sessions_count', $offer?->sessions_count ?? 1)" class="mt-1 w-full" required aria-describedby="count-help" /><p id="count-help" class="mt-2 text-xs text-gray-600">Individual: exactamente 1. Pack: entre 2 y 100 sesiones.</p></div>
        <div><x-input-label for="price_clp" value="Precio total en pesos chilenos (CLP)" /><x-text-input id="price_clp" name="price_clp" type="number" min="1" max="2147483647" step="1" :value="old('price_clp', $offer?->price_clp)" class="mt-1 w-full" required aria-describedby="price-help" /><p id="price-help" class="mt-2 text-xs text-gray-600">Solo pesos enteros, sin decimales. Cada sesión mantiene la duración de {{ config('appointments.session_minutes') }} minutos.</p></div>
        <input type="hidden" name="active" value="0"><label class="flex items-center gap-3"><input type="checkbox" name="active" value="1" @checked(old('active', $offer?->active ?? true)) class="rounded border-gray-300 text-green-700"><span class="text-sm">Oferta activa</span></label>
        <x-primary-button>Guardar oferta</x-primary-button>
    </form>
</x-app-layout>
