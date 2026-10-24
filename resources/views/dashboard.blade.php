<x-app-layout>
    @php($isTherapist = auth()->user()->isTherapist())
    <div class="mb-6">
        <p class="bee-kicker">Mi espacio · {{ auth()->user()->roleLabel() }}</p>
        <h1 class="mt-3 font-serif text-3xl sm:text-4xl">Hola, {{ explode(' ', trim(auth()->user()->name))[0] }}</h1>
        <p class="mt-3 text-sm text-[#6b7869]">{{ auth()->user()->isAdministrator() ? 'Gestiona las ofertas de la comunidad y consulta las solicitudes de compra.' : ($isTherapist ? 'Un espacio para acompañar el bienestar de tus pacientes.' : 'Qué bueno tenerte aquí. Dedica un momento a tu bienestar.') }}</p>
    </div>
    <section class="mb-6 rounded-2xl bg-[#234b32] p-5 text-white sm:p-6">
        <p class="text-xs uppercase tracking-[.2em] text-[#f5d569]">BeeHappy Aire Therapy</p>
        <h2 class="mt-2 font-serif text-2xl">{{ auth()->user()->isAdministrator() ? 'Sesiones y packs para nuestra comunidad.' : ($isTherapist ? 'Cuidar también es conectar.' : 'Tu pausa de bienestar empieza aquí.') }}</h2>
    </section>
    <div class="grid gap-5 md:grid-cols-2">
        @if (auth()->user()->isAdministrator())
            <section class="bee-card md:col-span-2"><h2 class="text-lg font-bold">Administración de sesiones y packs</h2><p class="mt-3 text-sm text-gray-600">Gestiona las ofertas de los apiterapeutas y consulta sus solicitudes de compra.</p><div class="mt-5 flex flex-wrap gap-3"><a href="{{ route('offers.index') }}" class="bee-button">Gestionar catálogo</a><a href="{{ route('purchases.index') }}" class="bee-secondary">Ver solicitudes de compra</a></div></section>
        @elseif ($isTherapist)
            <section class="bee-card md:col-span-2"><h2 class="text-lg font-bold">Sesiones y packs</h2><p class="mt-3 text-sm text-gray-600">Publica tus ofertas y revisa las solicitudes asociadas a tu catálogo.</p><div class="mt-5 flex flex-wrap gap-3"><a href="{{ route('offers.index') }}" class="bee-button">Gestionar mis ofertas</a><a href="{{ route('purchases.index') }}" class="bee-secondary">Solicitudes de compra</a></div></section>
        @elseif (auth()->user()->isPatient())
            <section class="bee-card md:col-span-2"><h2 class="text-lg font-bold">Mis compras</h2><p class="mt-3 text-sm text-gray-600">Consulta las solicitudes de sesiones y packs de cada profesional.</p><a href="{{ route('purchases.index') }}" class="bee-secondary mt-4">Ver mis compras</a></section>
        @endif
        @if (auth()->user()->isPatient())
            <section class="bee-card md:col-span-2">
                <h2 class="text-lg font-bold">Tu próxima cita</h2>
                @if ($nextAppointment)
                    <p class="mt-3 font-semibold">{{ $nextAppointment->starts_at->setTimezone(config('appointments.timezone'))->format('d/m/Y H:i') }} · {{ $nextAppointment->therapistProfile->user->name }}</p>
                    <x-santiago-time-note class="mt-2" />
                    <a href="{{ route('appointments.show', $nextAppointment) }}" class="bee-button mt-4">Ver mi próxima cita</a>
                @else
                    <p class="mt-3 text-sm text-gray-600">No tienes citas próximas.</p>
                @endif
                <a href="{{ route('appointments.index') }}" class="bee-secondary mt-4">Mis citas e historial</a>
            </section>
        @endif
        @if ($isTherapist)
            <section class="bee-card md:col-span-2"><h2 class="text-lg font-bold">Tu agenda</h2><p class="mt-2 text-sm text-gray-600">Consulta tus citas y registra la asistencia al terminar cada sesión.</p><a href="{{ route('appointments.agenda') }}" class="bee-button mt-4">Ver agenda</a></section>
            <section class="bee-card">
                <x-bee-icon name="sun" class="h-9 w-9 text-[#397442]" />
                <h2 class="mt-4 text-lg font-bold">Tus horarios de atención</h2>
                <p class="mt-2 text-sm leading-6 text-gray-600">Configura los tramos de tu semana y tu presentación profesional.</p>
                <a href="{{ route('therapist.schedules.edit') }}" class="bee-button mt-5">Configurar horarios</a>
            </section>
            <section class="bee-card">
                <h2 class="text-lg font-bold">Tiempo para ti</h2>
                <p class="mt-2 text-sm leading-6 text-gray-600">Bloquea un día completo o un intervalo sin cambiar tus horarios habituales.</p>
                <a href="{{ route('therapist.blocks.index') }}" class="bee-secondary mt-5">Administrar bloqueos</a>
            </section>
        @elseif (auth()->user()->isPatient())
            <section class="bee-card">
                <x-bee-icon name="sun" class="h-9 w-9 text-[#397442]" />
                <h2 class="mt-4 text-lg font-bold">Encuentra tu apiterapeuta</h2>
                <p class="mt-2 text-sm leading-6 text-gray-600">Conoce a los profesionales y consulta sus horarios disponibles.</p>
                <a href="{{ route('therapists.index') }}" class="bee-button mt-5">Ver apiterapeutas</a>
            </section>
            <section class="bee-card">
                <x-bee-icon class="h-9 w-9 text-[#397442]" />
                <h2 class="mt-4 text-lg font-bold">Mi perfil personal</h2>
                <p class="mt-2 text-sm leading-6 text-gray-600">Mantén tus datos y la seguridad de tu cuenta al día.</p>
                <a href="{{ route('profile.edit') }}" class="bee-secondary mt-5">Editar mi perfil</a>
            </section>
        @endif
    </div>
</x-app-layout>
