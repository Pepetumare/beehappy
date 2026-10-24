<x-app-layout>
    <p class="bee-kicker">Mi espacio profesional</p>
    <h1 class="mt-3 font-serif text-3xl">Mis bloqueos</h1>
    <p class="mb-6 mt-3 text-sm text-gray-600">Reserva tiempo personal sin cambiar tu semana habitual. Horarios de America/Santiago.</p>
    <x-availability-feedback />
    <nav class="mb-6 flex flex-wrap gap-3" aria-label="Configuración de disponibilidad">
        <a href="{{ route('therapist.schedules.edit') }}" class="bee-secondary">Horarios semanales</a>
        <a href="{{ route('therapist.blocks.index') }}" class="bee-button" aria-current="page">Administrar bloqueos</a>
    </nav>
    <div class="grid items-start gap-6 lg:grid-cols-2">
        <section class="bee-card">
            <h2 class="text-lg font-bold">Añadir bloqueo</h2>
            <form method="POST" action="{{ route('therapist.blocks.store') }}" class="mt-5 space-y-4" x-data="{ allDay: @js((string) old('all_day', '1')) }">
                @csrf
                <div><x-input-label for="date" value="Fecha" /><x-text-input id="date" type="date" name="date" :value="old('date')" required class="mt-1 w-full min-w-0" /></div>
                <div><x-input-label for="all_day" value="Tipo de bloqueo" /><select name="all_day" id="all_day" x-model="allDay" class="mt-1 w-full rounded-xl border-gray-300"><option value="1">Día completo</option><option value="0">Intervalo del día</option></select></div>
                <div x-show="allDay === '0'" x-cloak class="grid gap-4 sm:grid-cols-2">
                    <div><x-input-label for="start_time" value="Inicio" /><input id="start_time" name="start_time" type="time" step="60" value="{{ old('start_time') }}" :required="allDay === '0'" :disabled="allDay === '1'" class="mt-1 w-full min-w-0 rounded-xl border-gray-300"></div>
                    <div><x-input-label for="end_time" value="Término" /><input id="end_time" name="end_time" type="time" step="60" value="{{ old('end_time') }}" :required="allDay === '0'" :disabled="allDay === '1'" class="mt-1 w-full min-w-0 rounded-xl border-gray-300"></div>
                </div>
                <div><x-input-label for="reason" value="Motivo privado (opcional)" /><textarea id="reason" name="reason" rows="3" maxlength="500" class="mt-1 w-full rounded-xl border-gray-300" aria-describedby="reason-help">{{ old('reason') }}</textarea><p id="reason-help" class="mt-1 text-xs text-gray-600">Solo tú puedes ver este motivo.</p></div>
                <x-primary-button>Crear bloqueo</x-primary-button>
            </form>
        </section>
        <section class="min-w-0 space-y-4" aria-label="Bloqueos guardados">
            <h2 class="text-lg font-bold">Bloqueos guardados</h2>
            @forelse ($blocks as $block)
                <article class="bee-card">
                    <p class="text-sm font-semibold">{{ $block->starts_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i P') }}</p>
                    <p class="mt-1 text-sm">hasta {{ $block->ends_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i P') }}</p>
                    @if ($block->reason)<p class="mt-3 break-words text-sm text-gray-600">{{ $block->reason }}</p>@endif
                    <form method="POST" action="{{ route('therapist.blocks.destroy', $block) }}" class="mt-4">
                        @csrf @method('DELETE')
                        <button class="bee-secondary" aria-label="Eliminar bloqueo del {{ $block->starts_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i') }}">Eliminar bloqueo</button>
                    </form>
                </article>
            @empty
                <p class="bee-card text-sm text-gray-600">Aún no tienes bloqueos. Tu disponibilidad sigue los tramos semanales.</p>
            @endforelse
            {{ $blocks->links() }}
        </section>
    </div>
</x-app-layout>
