<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>BeeHappy · Mi espacio</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="min-h-screen pb-[calc(6rem+env(safe-area-inset-bottom))] lg:pb-8">
        @include('layouts.navigation')
        <main class="mx-auto max-w-6xl px-5 py-8 sm:px-8">
            @isset($header)<header class="mb-6">{{ $header }}</header>@endisset
            {{ $slot }}
        </main>
    </div>
</body>
</html>
