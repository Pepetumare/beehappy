<x-app-layout>
    <p class="bee-kicker">Mi espacio profesional</p>
    <h1 class="mt-3 font-serif text-3xl">Mis horarios</h1>
    <p class="mb-6 mt-3 text-sm text-gray-600">Define tus tramos semanales en hora de Santiago. Puedes añadir varios tramos por día.</p>
    <x-availability-feedback />
    <nav class="mb-6 flex flex-wrap gap-3" aria-label="Configuración de disponibilidad">
        <a href="{{ route('therapist.schedules.edit') }}" class="bee-button" aria-current="page">Horarios semanales</a>
        <a href="{{ route('therapist.blocks.index') }}" class="bee-secondary">Administrar bloqueos</a>
    </nav>
    @php
        $rows = old('schedules', $schedules->map(fn ($row) => [
            'day_of_week' => (string) $row->day_of_week,
            'start_time' => substr($row->start_time, 0, 5),
            'end_time' => substr($row->end_time, 0, 5),
        ])->values()->all());
        $days = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    @endphp
    <section class="bee-card" x-data="{ rows: @js($rows), nextKey: 0 }">
        <h2 class="text-lg font-bold">Tramos de atención</h2>
        <p class="mt-2 text-sm leading-6 text-gray-600">Cada tramo debe comenzar y terminar el mismo día. Los descansos se respetan; los tramos contiguos están permitidos.</p>
        <form method="POST" action="{{ route('therapist.schedules.update') }}" class="mt-6">
            @csrf
            @method('PUT')
            {{-- An empty array is normalized server-side; supports removing every shift. --}}
            <input type="hidden" name="empty_schedule" value="1">
            <template x-for="(row, index) in rows" :key="row.key ?? (row.key = ++nextKey)">
                <fieldset class="mb-4 grid min-w-0 gap-3 rounded-xl border border-gray-200 p-4 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end">
                    <legend class="px-1 text-sm font-semibold" x-text="'Tramo ' + (index + 1)"></legend>
                    <div><label :for="'day-' + index" class="block text-sm">Día</label>
                        <select :id="'day-' + index" :name="'schedules[' + index + '][day_of_week]'" x-model="row.day_of_week" class="mt-1 w-full rounded-xl border-gray-300" required>
                            @foreach ($days as $number => $label)<option value="{{ $number }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div><label :for="'start-' + index" class="block text-sm">Inicio</label><input :id="'start-' + index" :name="'schedules[' + index + '][start_time]'" x-model="row.start_time" type="time" step="60" class="mt-1 w-full min-w-0 rounded-xl border-gray-300" required></div>
                    <div><label :for="'end-' + index" class="block text-sm">Término</label><input :id="'end-' + index" :name="'schedules[' + index + '][end_time]'" x-model="row.end_time" type="time" step="60" class="mt-1 w-full min-w-0 rounded-xl border-gray-300" required></div>
                    <button type="button" @click="rows.splice(index, 1)" :aria-label="'Quitar tramo ' + (index + 1)" class="bee-secondary">Quitar</button>
                </fieldset>
            </template>
            <p x-show="rows.length === 0" class="mb-4 rounded-xl bg-[#f8f9f4] p-4 text-sm">Aún no hay tramos. Añade uno para ofrecer horarios disponibles.</p>
            <div class="flex flex-wrap gap-3">
                <button type="button" class="bee-secondary" @click="rows.push({day_of_week: '1', start_time: '', end_time: '', key: ++nextKey})" :disabled="rows.length >= 100">Añadir tramo</button>
                <x-primary-button>Guardar horarios</x-primary-button>
            </div>
            <noscript><p class="mt-4">Activa JavaScript para editar los tramos semanales.</p></noscript>
        </form>
    </section>
    <section class="bee-card mt-6">
        <h2 class="text-lg font-bold">Presentación profesional</h2>
        <p class="mt-2 text-sm text-gray-600">Estos datos y tu nombre se muestran a los pacientes. Tu correo de acceso permanece privado.</p>
        <form method="POST" action="{{ route('therapist.professional.update') }}" class="mt-5 space-y-4">
            @csrf @method('PATCH')
            <div><x-input-label for="commune" value="Comuna" /><x-text-input id="commune" name="commune" maxlength="120" class="mt-1 w-full" :value="old('commune', $profile->commune)" /></div>
            <div><x-input-label for="introduction" value="Presentación profesional" /><textarea id="introduction" name="introduction" rows="4" maxlength="2000" class="mt-1 w-full rounded-xl border-gray-300">{{ old('introduction', $profile->introduction) }}</textarea></div>
            <input type="hidden" name="accepts_appointments" value="0">
            <label class="flex items-center gap-3"><input type="checkbox" name="accepts_appointments" value="1" @checked(old('accepts_appointments', $profile->accepts_appointments)) class="rounded border-gray-300 text-green-700 focus:ring-green-700"><span class="text-sm">Acepto citas y quiero aparecer en el listado de apiterapeutas</span></label>
            <x-primary-button>Guardar presentación</x-primary-button>
        </form>
    </section>
</x-app-layout>
