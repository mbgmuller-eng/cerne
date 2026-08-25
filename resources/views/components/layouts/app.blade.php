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

    // "Meus clientes" e "Painel da carteira" não são telas DE um perfil —
    // são a área de gestão do consultor. Sem esta distinção, o perfil do
    // último cliente aberto (guardado na sessão) continuava ditando o menu
    // de navegação mesmo aqui, como se ainda estivéssemos dentro dele.
    $areaConsultor = request()->routeIs('consultant.*');
    $dentroDoPerfil = $profile && ! $areaConsultor;
    $mostraAsideDesktop = $dentroDoPerfil || $areaConsultor;

    // Preferência de tema da CONTA, não do navegador — ver
    // App\Enums\ThemePreference e resources/js/app.js.
    $theme = $user?->theme ?? \App\Enums\ThemePreference::System;
@endphp
<!DOCTYPE html>
<html
    lang="pt-BR"
    class="h-full @if ($theme === \App\Enums\ThemePreference::Dark) dark @endif"
    data-theme-preference="{{ $theme->value }}"
>
<head>
    <meta charset="utf-8">
    {{-- Decide o tema ANTES do primeiro paint — sem isto, "sistema" pisca
         claro e troca pra escuro um instante depois. Preferência explícita
         (claro/escuro) já nasce certa no atributo class acima, sem JS. --}}
    <script>
        (function () {
            if (document.documentElement.dataset.themePreference !== 'system') return;
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Cerne' }}</title>

    {{-- PWA --}}
    <link rel="manifest" href="{{ route('pwa.manifest') }}">
    <meta name="theme-color" content="#0b1d3a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Cerne">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-180.png') }}">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Preload + @font-face de Fraunces/Inter — sem isto os arquivos são
         baixados no build (ver vite.config.js) mas nunca ficam ligados à
         página, e o navegador cai no fallback do sistema silenciosamente. --}}
    {{ \Illuminate\Support\Facades\Vite::fonts() }}
</head>
<body class="h-full bg-paper text-slate-800 antialiased dark:text-slate-200" x-data="{ mais: false }">

<div class="flex min-h-full">

    {{-- ============================================================
         Barra lateral (desktop)
         ============================================================ --}}
    @if ($dentroDoPerfil)
        <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-r border-brand-950/5 bg-white lg:flex dark:border-white/10 dark:bg-slate-900">
            <div class="px-6 pt-6 pb-4">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    <x-brand-mark class="h-7 w-7" />
                    <span class="font-display text-2xl font-semibold tracking-tight text-brand-800 dark:text-white">Cerne</span>
                </a>
                <p class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">{{ $profile->profile_name }}</p>
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
                        <p class="px-3 pb-1 text-[11px] font-semibold tracking-wider text-slate-400 uppercase">Configurações</p>
                        <a href="{{ route('profile.privacy') }}" @class(['nav-item', 'nav-item-active' => request()->routeIs('profile.privacy')])>
                            <x-nav-icon name="lock" />
                            <span>Privacidade do casal</span>
                        </a>
                    </div>
                @endcan
            </nav>

            <div class="border-t border-brand-950/5 p-3 dark:border-white/10">
                @if ($context->isConsultant())
                    <div class="mb-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                        Você está vendo este perfil <span class="font-medium">como consultor</span>.
                    </div>
                @endif

                <div class="flex items-center gap-3 px-2 py-1.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800 dark:bg-white/10 dark:text-white">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $user->name }}</p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost -mr-1 px-2" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                        </button>
                    </form>
                </div>

                @if ($user->isConsultant())
                    <a href="{{ route('consultant.portfolio') }}" class="nav-item mt-1">
                        <x-nav-icon name="invest" />
                        <span>Painel da carteira</span>
                    </a>
                    <a href="{{ route('consultant.portfolio.insurance') }}" class="nav-item">
                        <x-nav-icon name="shield" />
                        <span>Seguros da carteira</span>
                    </a>
                    <a href="{{ route('consultant.portfolio.investments') }}" class="nav-item">
                        <x-nav-icon name="flow" />
                        <span>Investimentos da carteira</span>
                    </a>
                    <a href="{{ route('consultant.clients') }}" class="nav-item">
                        <x-nav-icon name="users" />
                        <span>Meus clientes</span>
                    </a>
                @endif

                <div class="mt-2 flex justify-center">
                    <x-theme-switcher :current="$theme" />
                </div>
            </div>
        </aside>
    @elseif ($areaConsultor)
        {{-- Área de gestão do consultor: carteira e clientes, não um perfil. --}}
        <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-r border-brand-950/5 bg-white lg:flex dark:border-white/10 dark:bg-slate-900">
            <div class="px-6 pt-6 pb-4">
                <a href="{{ route('consultant.portfolio') }}" class="flex items-center gap-2">
                    <x-brand-mark class="h-7 w-7" />
                    <span class="font-display text-2xl font-semibold tracking-tight text-brand-800 dark:text-white">Cerne</span>
                </a>
                <p class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">Painel do consultor</p>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
                <a href="{{ route('consultant.portfolio') }}" @class(['nav-item', 'nav-item-active' => request()->routeIs('consultant.portfolio')])>
                    <x-nav-icon name="invest" />
                    <span>Painel da carteira</span>
                </a>
                <a href="{{ route('consultant.portfolio.insurance') }}" @class(['nav-item', 'nav-item-active' => request()->routeIs('consultant.portfolio.insurance')])>
                    <x-nav-icon name="shield" />
                    <span>Seguros da carteira</span>
                </a>
                <a href="{{ route('consultant.portfolio.investments') }}" @class(['nav-item', 'nav-item-active' => request()->routeIs('consultant.portfolio.investments')])>
                    <x-nav-icon name="flow" />
                    <span>Investimentos da carteira</span>
                </a>
                <a href="{{ route('consultant.clients') }}" @class(['nav-item', 'nav-item-active' => request()->routeIs('consultant.clients')])>
                    <x-nav-icon name="users" />
                    <span>Meus clientes</span>
                </a>
            </nav>

            <div class="border-t border-brand-950/5 p-3 dark:border-white/10">
                <div class="flex items-center gap-3 px-2 py-1.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800 dark:bg-white/10 dark:text-white">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $user->name }}</p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost -mr-1 px-2" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                        </button>
                    </form>
                </div>

                <div class="mt-2 flex justify-center">
                    <x-theme-switcher :current="$theme" />
                </div>
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
            'sticky top-0 z-20 border-b border-brand-950/5 bg-white/90 backdrop-blur dark:border-white/10 dark:bg-slate-900/90',
            'lg:hidden' => $mostraAsideDesktop,
        ])>
            <div class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3 px-4">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-display text-xl font-semibold tracking-tight text-brand-800 dark:text-white">
                    <x-brand-mark class="h-6 w-6" />
                    Cerne
                </a>

                <div class="flex items-center gap-2">
                    @if ($context->isConsultant())
                        <span class="badge bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">consultor</span>
                    @endif

                    @if ($user?->isConsultant())
                        <a href="{{ route('consultant.portfolio') }}" class="btn-ghost" title="Painel da carteira">
                            <x-nav-icon name="invest" class="h-4 w-4" />
                            <span class="ml-1.5 hidden sm:inline">Carteira</span>
                        </a>
                        <a href="{{ route('consultant.portfolio.insurance') }}" class="btn-ghost" title="Seguros da carteira">
                            <x-nav-icon name="shield" class="h-4 w-4" />
                            <span class="ml-1.5 hidden sm:inline">Seguros</span>
                        </a>
                        <a href="{{ route('consultant.portfolio.investments') }}" class="btn-ghost" title="Investimentos da carteira">
                            <x-nav-icon name="flow" class="h-4 w-4" />
                            <span class="ml-1.5 hidden sm:inline">Invest.</span>
                        </a>
                        <a href="{{ route('consultant.clients') }}" class="btn-ghost" title="Meus clientes">
                            <x-nav-icon name="users" class="h-4 w-4" />
                            <span class="ml-1.5 hidden sm:inline">Clientes</span>
                        </a>
                    @endif

                    <x-theme-switcher :current="$theme" />

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
            'pb-24 lg:pb-10' => $dentroDoPerfil,  // espaço para a barra inferior no celular
        ])>
            @if (session('status'))
                <div class="mb-6 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">
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
@if ($dentroDoPerfil)
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
            class="relative rounded-t-3xl bg-white px-4 pt-3 pb-4 shadow-[0_-8px_32px_-8px_rgb(11_29_58_/_0.2)] dark:bg-slate-900"
        >
            <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-slate-200 dark:bg-slate-700"></div>
            <div class="grid grid-cols-4 gap-1">
                @foreach ($tabsMais as [$route, $label, $curto, $icone])
                    <a href="{{ route($route) }}" @class(['tab-item rounded-xl py-3', 'tab-item-active bg-brand-50 dark:bg-accent-500/15' => request()->routeIs($route)])>
                        <x-nav-icon :name="$icone" class="h-6 w-6" />
                        <span>{{ $curto }}</span>
                    </a>
                @endforeach
                @can('updatePrivacy', $profile)
                    <a href="{{ route('profile.privacy') }}" @class(['tab-item rounded-xl py-3', 'tab-item-active bg-brand-50 dark:bg-accent-500/15' => request()->routeIs('profile.privacy')])>
                        <x-nav-icon name="lock" class="h-6 w-6" />
                        <span>Privacidade</span>
                    </a>
                @endcan
            </div>
        </div>

        {{-- Abas --}}
        <nav class="relative flex border-t border-brand-950/5 bg-white/95 backdrop-blur dark:border-white/10 dark:bg-slate-900/95" style="padding-bottom: env(safe-area-inset-bottom)">
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
