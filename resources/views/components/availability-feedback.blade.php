@if (session('success'))
    <p role="status" class="mb-6 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-900">{{ session('success') }}</p>
@endif
@if ($errors->any())
    <div role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
        <p class="font-bold">Revisa los datos antes de continuar.</p>
        <ul class="mt-2 list-inside list-disc">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
