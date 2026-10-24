@php
    $therapistNav = auth()->user()->isTherapist();
    $items = [
        ['dashboard', 'dashboard', 'home', 'Inicio'],
        ...($therapistNav ? [['appointments.agenda', 'appointments.agenda', 'sun', 'Agenda']] : []),
        ...(auth()->user()->isPatient() ? [['appointments.index', 'appointments.index', 'sun', 'Mis citas']] : []),
        ...(($therapistNav || auth()->user()->isAdministrator()) ? [['offers.index', 'offers.*', 'sun', 'Sesiones']] : []),
        ...(auth()->user()->hasKnownRole() ? [['purchases.index', 'purchases.*', 'sun', 'Compras']] : []),
        ...($therapistNav ? [['therapist.schedules.edit', 'therapist.schedules.*', 'sun', 'Horarios']] : []),
        ...(auth()->user()->isPatient() ? [['therapists.index', 'therapists.*', 'sun', 'Apiterapeutas']] : []),
        ...($therapistNav ? [['therapist.blocks.index', 'therapist.blocks.*', 'sun', 'Bloqueos']] : []),
        ['profile.edit', 'profile.*', 'user', 'Perfil'],
    ];
@endphp
<header x-data="{ open: false }" class="border-b border-[#e3e8dd] bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-5 py-4 sm:px-8">
        <a href="{{ route('dashboard') }}" aria-label="BeeHappy, inicio"><x-application-logo class="scale-90 origin-left" /></a>
        <nav class="hidden items-center gap-4 lg:flex" aria-label="Navegación principal">
            @foreach ($items as [$route, $pattern, $icon, $label])
                <a href="{{ route($route) }}" @class(['text-sm font-semibold', 'text-[#8a6800]' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="relative">
            <button @click="open = !open" :aria-expanded="open" aria-controls="account-menu" class="flex items-center gap-2 rounded-xl border border-[#e3e8dd] p-2 text-sm" aria-label="Menú de cuenta">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-[#eef3e6] font-bold">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                <span aria-hidden="true">⌄</span>
            </button>
            <div id="account-menu" x-cloak x-show="open" @click.outside="open = false" @keydown.escape.window="open = false" class="absolute right-0 z-30 mt-2 w-48 rounded-xl border bg-white p-3 shadow-lg">
                <a href="{{ route('profile.edit') }}" class="block rounded-lg p-2">Mi perfil</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="w-full rounded-lg p-2 text-left text-sm">Cerrar sesión</button></form>
            </div>
        </div>
    </div>
</header>
<nav aria-label="Navegación móvil" class="fixed inset-x-0 bottom-0 z-20 flex border-t border-[#e3e8dd] bg-white pb-[env(safe-area-inset-bottom)] lg:hidden">
    @foreach ($items as [$route, $pattern, $icon, $label])
        <a href="{{ route($route) }}" @class(['flex min-w-0 flex-1 flex-col items-center gap-1 px-1 py-3 text-xs', 'bg-[#fff9df] font-bold text-[#795d00]' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif>
            <x-bee-icon :name="$icon" />{{ $label }}
        </a>
    @endforeach
</nav>
