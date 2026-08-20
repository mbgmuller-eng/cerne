@php
    /** @var \App\Support\ProfileContext $context */
    $context = app(\App\Support\ProfileContext::class);
    $profile = $context->profile();
    $user = auth()->user();

    // [rota, rótulo completo, rótulo curto para a barra inferior, ícone]
    $nav = [
        ['dashboard', 'Visão geral', 'Início', 'home'],
        ['cashflow.index', 'Fluxo de caixa', 'Fluxo', 'flow'],
        ['fixedbills.index', 'Contas fixas', 'Fixas', 'bills'],
        ['accounts.index', 'Contas & Cartões', 'Contas', 'cards'],
        ['investments.index', 'Investimentos', 'Invest.', 'invest'],
        ['insurance.index', 'Seguros', 'Seguros', 'shield'],
        ['goals.index', 'Objetivos', 'Metas', 'target'],
        ['documents.index', 'Importar', 'Importar', 'upload'],
    ];

    // Na barra inferior cabem 5; o resto vai para "Mais".
    $tabsPrincipais = array_slice($nav, 0, 4);
    $tabsMais = array_slice($nav, 4);
    $emMais = collect($tabsMais)->contains(fn ($t) => request()->routeIs($t[0]));
@endphp
<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Cerne' }}</title>

    {{-- PWA --}}
    <link rel="manifest" href="{{ route('pwa.manifest') }}">
    <meta name="theme-color" content="#1c5449">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Cerne">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-180.png') }}">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-paper text-stone-800 antialiased" x-data="{ mais: false }">

<div class="flex min-h-full">

    {{-- ============================================================
         Barra lateral (desktop)
         ============================================================ --}}
    @if ($profile)
        <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-r border-brand-950/5 bg-white lg:flex">
            <div class="px-6 pt-6 pb-4">
                <a href="{{ route('dashboard') }}" class="block">
                    <span class="font-display text-2xl font-semibold tracking-tight text-brand-800">Cerne</span>
                </a>
                <p class="mt-1 truncate text-xs text-stone-500">{{ $profile->profile_name }}</p>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
                @foreach ($nav as [$route, $label, $curto, $icone])
                    <a href="{{ route($route) }}" @class(['nav-item', 'nav-item-active' => request()->routeIs($route)])>
                        <x-nav-icon :name="$icone" />
                        <span>{{ $label }}</span>
                    </a>
                @endforeach

                @can('updatePrivacy', $profile)
                    <div class="pt-4">
                        <p class="px-3 pb-1 text-[11px] font-semibold tracking-wider text-stone-400 uppercase">Configurações</p>
                        <a href="{{ route('profile.privacy') }}" @class(['nav-item', 'nav-item-active' => request()->routeIs('profile.privacy')])>
                            <x-nav-icon name="lock" />
                            <span>Privacidade do casal</span>
                        </a>
                    </div>
                @endcan
            </nav>

            <div class="border-t border-brand-950/5 p-3">
                @if ($context->isConsultant())
                    <div class="mb-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-amber-200">
                        Você está vendo este perfil <span class="font-medium">como consultor</span>.
                    </div>
                @endif

                <div class="flex items-center gap-3 px-2 py-1.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-stone-800">{{ $user->name }}</p>
                        <p class="truncate text-xs text-stone-500">{{ $user->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost -mr-1 px-2" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                        </button>
                    </form>
                </div>

                @if ($user->isConsultant())
                    <a href="{{ route('consultant.clients') }}" class="nav-item mt-1">
                        <x-nav-icon name="users" />
                        <span>Meus clientes</span>
                    </a>
                @endif
            </div>
        </aside>
    @endif

    {{-- ============================================================
         Área principal
         ============================================================ --}}
    <div class="flex min-w-0 flex-1 flex-col">

        {{-- Cabeçalho compacto: em desktop com perfil ele some (a lateral
             já cumpre o papel); sem perfil ou no celular ele fica. --}}
        <header @class([
            'sticky top-0 z-20 border-b border-brand-950/5 bg-white/90 backdrop-blur',
            'lg:hidden' => $profile,
        ])>
            <div class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3 px-4">
                <a href="{{ route('dashboard') }}" class="font-display text-xl font-semibold tracking-tight text-brand-800">Cerne</a>

                <div class="flex items-center gap-2">
                    @if ($context->isConsultant())
                        <span class="badge bg-amber-50 text-amber-900 ring-1 ring-amber-200">consultor</span>
                    @endif

                    @if ($user?->isConsultant())
                        <a href="{{ route('consultant.clients') }}" class="btn-ghost">
                            <x-nav-icon name="users" class="h-4 w-4" />
                            <span class="ml-1.5 hidden sm:inline">Clientes</span>
                        </a>
                    @endif

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                            <span class="ml-1.5 hidden sm:inline">Sair</span>
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main @class([
            'mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 sm:py-8 lg:px-10 lg:py-10',
            'pb-24 lg:pb-10' => $profile,  // espaço para a barra inferior no celular
        ])>
            @if (session('status'))
                <div class="mb-6 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>

{{-- ============================================================
     Barra inferior (celular e tablet)
     ============================================================ --}}
@if ($profile)
    <div class="fixed inset-x-0 bottom-0 z-30 lg:hidden">
        {{-- Gaveta "Mais" --}}
        <div
            x-show="mais"
            x-transition.opacity
            x-cloak
            @click="mais = false"
            class="fixed inset-0 bg-brand-950/30 backdrop-blur-sm"
        ></div>
        <div
            x-show="mais"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-full"
            x-transition:enter-end="translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-y-0"
            x-transition:leave-end="translate-y-full"
            x-cloak
            class="relative rounded-t-3xl bg-white px-4 pt-3 pb-4 shadow-[0_-8px_32px_-8px_rgb(10_33_29_/_0.2)]"
        >
            <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-stone-200"></div>
            <div class="grid grid-cols-4 gap-1">
                @foreach ($tabsMais as [$route, $label, $curto, $icone])
                    <a href="{{ route($route) }}" @class(['tab-item rounded-xl py-3', 'tab-item-active bg-brand-50' => request()->routeIs($route)])>
                        <x-nav-icon :name="$icone" class="h-6 w-6" />
                        <span>{{ $curto }}</span>
                    </a>
                @endforeach
                @can('updatePrivacy', $profile)
                    <a href="{{ route('profile.privacy') }}" @class(['tab-item rounded-xl py-3', 'tab-item-active bg-brand-50' => request()->routeIs('profile.privacy')])>
                        <x-nav-icon name="lock" class="h-6 w-6" />
                        <span>Privacidade</span>
                    </a>
                @endcan
            </div>
        </div>

        {{-- Abas --}}
        <nav class="relative flex border-t border-brand-950/5 bg-white/95 backdrop-blur" style="padding-bottom: env(safe-area-inset-bottom)">
            @foreach ($tabsPrincipais as [$route, $label, $curto, $icone])
                <a href="{{ route($route) }}" @class(['tab-item', 'tab-item-active' => request()->routeIs($route)])>
                    <x-nav-icon :name="$icone" class="h-6 w-6" />
                    <span>{{ $curto }}</span>
                </a>
            @endforeach
            <button type="button" @click="mais = !mais" @class(['tab-item', 'tab-item-active' => $emMais])>
                <x-nav-icon name="menu" class="h-6 w-6" />
                <span>Mais</span>
            </button>
        </nav>
    </div>
@endif

<script>
    // Cacheia apenas os arquivos estáticos. Dado financeiro nunca entra
    // no cache do navegador — ver public/sw.js.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('{{ url('sw.js') }}').catch(() => {});
        });
    }
</script>

</body>
</html>
