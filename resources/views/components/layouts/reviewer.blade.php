<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <script>
        (function () {
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- O link é a chave de entrada: nunca indexado nem repassado a outro site. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title ?? 'Revisão das regras · Cerne' }}</title>
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ \Illuminate\Support\Facades\Vite::fonts() }}
    @livewireStyles
</head>
<body class="min-h-full bg-paper text-slate-800 antialiased dark:text-slate-200">
    <header class="bg-brand-800 px-4 py-4 text-white">
        <div class="mx-auto flex max-w-4xl items-center gap-2.5">
            <x-brand-mark class="h-7 w-7" />
            <span class="font-display text-xl font-semibold tracking-tight">Cerne</span>
            <span class="text-sm text-brand-100">Revisão das regras de atividade física</span>
        </div>
    </header>
    <main class="mx-auto max-w-4xl px-4 py-6">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
