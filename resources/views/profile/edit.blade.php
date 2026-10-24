<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Mi perfil
        </h2>
    </x-slot>

    <section class="bee-card mb-6 flex items-center gap-5"><span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-[#fff2b8] text-2xl font-bold">{{ mb_substr($user->name, 0, 1) }}</span><div><h1 class="text-xl font-bold">{{ $user->name }}</h1><p class="mt-1 text-sm text-[#63815c]">{{ $user->roleLabel() }} · BeeHappy</p></div></section><div>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bee-card">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="bee-card">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="bee-card">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
