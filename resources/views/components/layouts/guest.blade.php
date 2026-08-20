<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Cerne' }}</title>
    <link rel="manifest" href="{{ route('pwa.manifest') }}">
    <meta name="theme-color" content="#1c5449">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-paper text-stone-800 antialiased">
    <div class="flex min-h-full">

        {{-- Painel de marca: só em telas largas --}}
        <div class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-brand-800 p-12 text-white lg:flex">
            <div class="pointer-events-none absolute -top-32 -right-32 h-96 w-96 rounded-full bg-brand-600/40 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-40 -left-24 h-96 w-96 rounded-full bg-brand-950/60 blur-3xl"></div>

            <span class="relative font-display text-3xl font-semibold tracking-tight">Cerne</span>

            <div class="relative max-w-md">
                <p class="font-display text-4xl leading-tight font-medium">
                    Suas finanças, acompanhadas de perto.
                </p>
                <p class="mt-4 text-brand-100">
                    Fluxo de caixa, investimentos, seguros e objetivos — num só lugar, com a privacidade que o casal escolher.
                </p>
            </div>

            <p class="relative text-xs text-brand-200">Consultoria financeira</p>
        </div>

        {{-- Formulário --}}
        <div class="flex w-full flex-col items-center justify-center px-4 py-12 lg:w-1/2">
            <div class="w-full max-w-sm">
                <div class="mb-8 lg:hidden">
                    <span class="font-display text-3xl font-semibold tracking-tight text-brand-800">Cerne</span>
                    <p class="mt-1 text-sm text-stone-500">Consultoria financeira</p>
                </div>

                <div class="card p-8">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</body>
</html>
