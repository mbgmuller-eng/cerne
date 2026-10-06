<!DOCTYPE html>
<html lang="pt-BR" class="h-full" data-theme-preference="system">
<head>
    <meta charset="utf-8">
    {{-- Ver components/layouts/app.blade.php para o porquê deste script. --}}
    <script>
        (function () {
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Cerne' }}</title>
    <link rel="manifest" href="{{ route('pwa.manifest') }}">
    <meta name="theme-color" content="#0b1d3a">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{ \Illuminate\Support\Facades\Vite::fonts() }}
    @livewireStyles
</head>
<body class="h-full bg-paper text-slate-800 antialiased dark:bg-slate-900 dark:text-slate-200">
    <header class="sticky top-0 z-10 border-b border-slate-200/70 bg-paper/90 backdrop-blur dark:border-white/10 dark:bg-slate-900/90">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                <x-brand-mark class="h-8 w-8" />
                <span class="font-display text-xl font-semibold tracking-tight text-brand-800 dark:text-white">Cerne</span>
            </a>

            <nav class="hidden items-center gap-6 text-sm font-medium text-slate-600 dark:text-slate-300 md:flex">
                <a href="#modulos" class="hover:text-brand-800 dark:hover:text-white">Módulos</a>
                <a href="#planos" class="hover:text-brand-800 dark:hover:text-white">Planos para você</a>
                <a href="#planos-profissionais" class="hover:text-brand-800 dark:hover:text-white">Consultores e corretores</a>
            </nav>

            <div class="flex items-center gap-3">
                <a href="{{ route('login') }}" class="btn-ghost">Minha conta</a>
                <a href="#planos" class="btn-primary">Assinar</a>
            </div>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200/70 py-10 dark:border-white/10">
        <div class="mx-auto flex max-w-6xl flex-col items-center gap-3 px-4 text-center text-xs text-slate-500 dark:text-slate-400 sm:px-6">
            <span class="flex items-center gap-2">
                <x-brand-mark class="h-5 w-5" />
                Cerne
            </span>
            <p>O centro da sua vida organizada.</p>
            <p>
                <a href="{{ route('legal.terms') }}" class="underline hover:text-slate-700 dark:hover:text-slate-200">Termos de Uso</a>
                ·
                <a href="{{ route('login') }}" class="underline hover:text-slate-700 dark:hover:text-slate-200">Minha conta</a>
            </p>
        </div>
    </footer>
    @livewireScripts
</body>
</html>
