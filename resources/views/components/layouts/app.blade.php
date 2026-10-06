@php
    /** @var \App\Support\ProfileContext $context */
    $context = app(\App\Support\ProfileContext::class);
    $profile = $context->profile();
    $user = auth()->user();

    // [rota, rótulo completo, rótulo curto para a barra inferior, ícone]
    // Corretor dentro do perfil de um cliente só vê Seguros — o resto é
    // tela financeira, travada no mount() de cada uma (ver
    // RequiresActiveProfile e os guards em cada tela). O menu já não
    // oferece o que ele não pode abrir.
    //
    // Agrupado por setor pro menu lateral (ver $navSections abaixo) — item
    // de Seguros chama "Apólices", não "Seguros", pra não repetir o nome
    // do cabeçalho da seção logo em cima dele. Primeiro passo de uma
    // reorganização maior por produto (Cerne Finanças/Seguros/...), ainda
    // sem cor própria por setor.
    $navFinancas = [
        ['dashboard', 'Visão geral', 'Início', 'home'],
        ['cashflow.index', 'Fluxo de caixa', 'Fluxo', 'flow'],
        ['fixedbills.index', 'Contas fixas', 'Fixas', 'bills'],
        ['accounts.index', 'Contas & Cartões', 'Contas', 'cards'],
        ['investments.index', 'Investimentos', 'Invest.', 'invest'],
        ['goals.index', 'Objetivos', 'Metas', 'target'],
        ['documents.index', 'Importar', 'Importar', 'upload'],
        ['categorization-rules.index', 'Regras de categorização', 'Regras', 'tag'],
    ];
    $navSeguros = [
        ['insurance.index', 'Apólices', 'Seguros', 'shield'],
    ];

    $navSaude = [
        ['health.gym.index', 'Academia', 'Academia', 'dumbbell'],
        ['health.card.index', 'Ficha de Saúde', 'Ficha', 'heart'],
        ['health.appointments.index', 'Agenda de Saúde', 'Agenda', 'calendar'],
    ];

    $navDocumentos = [
        ['documents.vault.index', 'Meus documentos', 'Documentos', 'folder'],
    ];

    // Saúde é dado pessoal: consultor/corretor operando o perfil de um
    // cliente NÃO ganha a seção (ver PersonalHealthScope/CoupleHealthScope
    // — não têm acesso a NADA ali, então a seção nem aparece). Documentos é
    // diferente: o profissional pode ver um subconjunto (ver
    // DocumentVisibilityScope), então a seção continua aparecendo pra ele,
    // só a lista dentro é que fica menor.
    $navSections = $user?->isBroker()
        ? [['label' => 'Seguros', 'items' => $navSeguros]]
        : array_values(array_filter([
            ['label' => 'Finanças', 'items' => $navFinancas],
            ['label' => 'Seguros', 'items' => $navSeguros],
            ['label' => 'Documentos', 'items' => $navDocumentos],
            $context->isConsultant() ? null : ['label' => 'Saúde', 'items' => $navSaude],
        ]));

    // Cor da barra de dentro do perfil: a do SETOR que a pessoa está
    // vendo, não o tema claro/escuro (esse continua só no conteúdo — ver
    // memory: project_multi_product_branding). Corretor só tem Seguros,
    // então é sempre verde pra ele, em qualquer tela dentro do perfil
    // (inclusive Minha conta); quem tem tudo começa em Finanças/navy e só
    // vira verde dentro de Seguros e violeta dentro de Saúde.
    $moduloAtivo = match (true) {
        (bool) $user?->isBroker() => 'seguros',
        request()->routeIs('health.*') => 'saude',
        request()->routeIs('documents.vault.*') => 'documentos',
        request()->routeIs('insurance.index') => 'seguros',
        default => 'financas',
    };
    $corBarraModulo = [
        'seguros' => 'bg-seguros-800',
        'saude' => 'bg-saude-800',
        'documentos' => 'bg-documentos-800',
        'financas' => 'bg-brand-800',
    ][$moduloAtivo];

    // No escuro a barra volta a ser neutra (slate-900, como o app sempre
    // foi) — preencher a barra inteira com uma cor saturada no escuro
    // cansa a vista (Marcelo achou "não agradável no PC"). A cor do
    // módulo continua aparecendo, só que como acento no item ativo, não
    // mais como fundo da barra inteira. Isso é só pro DESKTOP — no
    // celular o preenchimento cheio já está confirmado como bom nos dois
    // temas, não mexe.
    $corAtivoEscuroDesktop = [
        'seguros' => 'dark:bg-seguros-800/60 dark:text-seguros-200',
        'saude' => 'dark:bg-saude-800/60 dark:text-saude-200',
        'documentos' => 'dark:bg-documentos-800/60 dark:text-documentos-200',
        'financas' => 'dark:bg-accent-500/15 dark:text-accent-400',
    ][$moduloAtivo];

    // Rótulo de cada seção sempre no tom do próprio setor, esteja a barra
    // preenchida daquele setor ou não — é o que deixa "onde eu poderia
    // ir" legível mesmo quando a barra inteira está em navy.
    $navSections = collect($navSections)->map(function (array $s) {
        $tintPorSetor = [
            'Finanças' => 'text-white/40',
            'Seguros' => 'text-seguros-200',
            'Documentos' => 'text-documentos-200',
            'Saúde' => 'text-saude-200',
        ];

        return $s + ['tint' => $tintPorSetor[$s['label']] ?? 'text-white/40'];
    })->all();

    // Barra inferior do celular: uma aba por CATEGORIA (Finanças, Seguros,
    // Documentos, Saúde) mais "Minha conta" — 5 cabem. Categoria com várias
    // telas abre uma gaveta com elas; com uma tela só vai direto, uma gaveta
    // de um item só seria um toque a mais sem ganho nenhum. As categorias
    // vêm de $navSections, então corretor (só Seguros) e consultor com
    // cliente aberto (sem Saúde) ganham só as abas a que têm direito.
    $iconesDasAbas = ['Finanças' => 'invest', 'Seguros' => 'shield', 'Documentos' => 'folder', 'Saúde' => 'heart'];
    $naMinhaConta = request()->routeIs('my-account');
    $abasInferiores = collect($navSections)->map(function (array $s) use ($iconesDasAbas, $moduloAtivo, $naMinhaConta) {
        $chave = \Illuminate\Support\Str::slug($s['label']);

        return [
            'chave' => $chave,
            'label' => $s['label'],
            'icone' => $iconesDasAbas[$s['label']] ?? 'menu',
            'tint' => $s['tint'],
            'items' => $s['items'],
            'ativa' => ! $naMinhaConta && $moduloAtivo === $chave,
            'direta' => count($s['items']) === 1,
        ];
    })->all();
    // "Painel da carteira" e as telas irmãs não são telas DE um perfil —
    // são a área de gestão do consultor. Sem esta distinção, o perfil do
    // último cliente aberto (guardado na sessão) continuava ditando o menu
    // de navegação mesmo aqui, como se ainda estivéssemos dentro dele.
    // "Minha conta" e "Assinatura" são rotas compartilhadas com o cliente
    // comum (não têm prefixo consultant.*) — mas, pro profissional sem
    // cliente nenhum aberto, são telas DELE, não de ninguém, e precisam do
    // mesmo layout da área do consultor. Sem isto caíam no "nenhuma área"
    // (sem barra lateral, cabeçalho compacto mesmo no PC) por não ter
    // $profile nem bater com consultant.* — sem jeito nenhum de navegar.
    $areaConsultor = request()->routeIs('consultant.*')
        || ($profile === null && $user?->isLinkedProfessional() && request()->routeIs(['my-account', 'subscription.index']));
    $areaAdmin = request()->routeIs('admin.*');
    $dentroDoPerfil = $profile && ! $areaConsultor && ! $areaAdmin;
    $mostraAsideDesktop = $dentroDoPerfil || $areaConsultor || $areaAdmin;

    // Nav da barra inferior nas áreas de consultor/admin — cabe tudo
    // direto, sem gaveta "Mais" (são poucas telas, ao contrário do menu
    // de dentro do perfil). Mesmos itens da <aside> de desktop de cada
    // área, só que achatados pra caber embaixo.
    // Corretor não tem Painel da carteira nem Investimentos — são telas
    // financeiras. A dele é só Seguros da carteira + Leads.
    $navConsultor = $user?->isBroker() ? [
        ['consultant.portfolio.insurance', 'Seguros da carteira', 'Seguros', 'shield'],
        ['consultant.portfolio.important-dates', 'Datas importantes', 'Datas', 'bell'],
        ['consultant.leads', 'Leads', 'Leads', 'contact'],
    ] : [
        ['consultant.portfolio', 'Painel da carteira', 'Carteira', 'invest'],
        ['consultant.portfolio.insurance', 'Seguros da carteira', 'Seguros', 'shield'],
        ['consultant.portfolio.investments', 'Investimentos da carteira', 'Invest.', 'flow'],
        ['consultant.portfolio.important-dates', 'Datas importantes', 'Datas', 'bell'],
        ['consultant.leads', 'Leads', 'Leads', 'contact'],
    ];
    if ($user?->isPlatformAdmin()) {
        $navConsultor[] = ['admin.users', 'Painel admin', 'Admin', 'admin'];
    }

    $navAdmin = [
        ['admin.users', 'Contas e perfis', 'Contas', 'admin'],
        ['admin.banks', 'Bancos', 'Bancos', 'cards'],
    ];
    if ($user?->isLinkedProfessional()) {
        $navAdmin[] = $user->isBroker()
            ? ['consultant.portfolio.insurance', 'Seguros da carteira', 'Seguros', 'shield']
            : ['consultant.portfolio', 'Painel da carteira', 'Carteira', 'invest'];
    }

    // "Página inicial" de cada profissional — corretor não tem Painel da
    // carteira (é tela financeira), a dele é Seguros da carteira.
    $homeRouteProfissional = $user?->isBroker() ? 'consultant.portfolio.insurance' : 'consultant.portfolio';

    // Só conta se for mesmo relevante — poupa uma query em toda página
    // pra quem não é admin.
    $bancosPendentes = $user?->isPlatformAdmin() ? \App\Models\Bank::withoutTaxonomyScope()->pending()->count() : 0;

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
    <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
    <title>{{ $title ?? 'Cerne' }}</title>

    {{-- PWA --}}
    <link rel="manifest" href="{{ route('pwa.manifest') }}">
    <meta name="theme-color" content="#0b1d3a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Cerne">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-180.png') }}">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">

    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/push.js', 'resources/js/gym.js'])
    {{-- Preload + @font-face de Fraunces/Inter — sem isto os arquivos são
         baixados no build (ver vite.config.js) mas nunca ficam ligados à
         página, e o navegador cai no fallback do sistema silenciosamente. --}}
    {{ \Illuminate\Support\Facades\Vite::fonts() }}
</head>
<body class="h-full bg-paper text-slate-800 antialiased dark:text-slate-200" x-data="{ secao: null }">

<div class="flex min-h-full">

    {{-- ============================================================
         Barra lateral (desktop)
         ============================================================ --}}
    @if ($dentroDoPerfil)
        {{-- Fundo = módulo ativo no claro (ver $corBarraModulo); no
             escuro volta a ser neutro (dark:bg-slate-900) e o módulo vira
             só o acento do item ativo (ver $corAtivoEscuroDesktop) — por
             isso os textos aqui usam tom fixo (branco translúcido), que
             funciona em cima de QUALQUER um dos dois fundos escuros. --}}
        <aside @class(['sticky top-0 hidden h-screen w-64 shrink-0 flex-col lg:flex dark:bg-slate-900', $corBarraModulo])>
            <div class="px-6 pt-6 pb-4">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    <x-brand-mark class="h-7 w-7" />
                    <span class="font-display text-2xl font-semibold tracking-tight text-white">Cerne</span>
                </a>
                <p class="mt-1 truncate text-xs text-white/50">{{ $profile->profile_name }}</p>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
                @foreach ($navSections as $secao)
                    <div @class(['pt-4' => ! $loop->first])>
                        <p @class(['px-3 pb-1 text-[10px] font-semibold tracking-wide uppercase', $secao['tint']])>
                            {{ $secao['label'] }}
                            @if ($secao['emBreve'] ?? false)
                                <span class="ml-1 rounded bg-white/10 px-1.5 py-0.5 text-[9px] font-normal tracking-normal text-white/50 normal-case">em breve</span>
                            @endif
                        </p>
                        @foreach ($secao['items'] as [$route, $label, $curto, $icone])
                            <a href="{{ route($route) }}" @class(['nav-item-chrome', 'nav-item-chrome-active '.$corAtivoEscuroDesktop => request()->routeIs($route)])>
                                <x-nav-icon :name="$icone" />
                                <span>{{ $label }}</span>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="border-t border-white/10 p-3">
                @if ($context->isConsultant())
                    <div class="mb-2 rounded-xl bg-amber-500/10 px-3 py-2 text-xs text-amber-300 ring-1 ring-amber-500/20">
                        Você está vendo este perfil <span class="font-medium">como {{ $user?->isBroker() ? 'corretor' : 'consultor' }}</span>.
                    </div>
                @endif

                <a href="{{ route('my-account') }}" @class(['nav-item-chrome mb-1', 'nav-item-chrome-active '.$corAtivoEscuroDesktop => request()->routeIs('my-account')])>
                    <x-nav-icon name="users" />
                    <span>Minha conta</span>
                </a>

                <div class="flex items-center gap-3 px-2 py-1.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-white">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-white">{{ $user->name }}</p>
                        <p class="truncate text-xs text-white/50">{{ $user->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost !text-white/60 px-2" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                        </button>
                    </form>
                    {{-- O botão do sino é do componente compartilhado
                         (usado no cabeçalho também, com fundo claro) — o
                         seletor aqui força a cor certa só nesta instância,
                         sem mexer no componente em si. --}}
                    <div class="[&>div>button]:!text-white/60">
                        <livewire:notifications.notification-center direction="up" />
                    </div>
                </div>

                @if ($user->isLinkedProfessional())
                    @foreach ($navConsultor as [$route, $label, $curto, $icone])
                        @if ($route !== 'admin.users')
                            <a href="{{ route($route) }}" @class(['nav-item-chrome', 'mt-1' => $loop->first])>
                                <x-nav-icon :name="$icone" />
                                <span>{{ $label }}</span>
                            </a>
                        @endif
                    @endforeach
                @endif

                @if ($user->isPlatformAdmin())
                    <a href="{{ route('admin.users') }}" class="nav-item-chrome mt-1">
                        <x-nav-icon name="admin" />
                        <span>Painel admin</span>
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
                <a href="{{ route($homeRouteProfissional) }}" class="flex items-center gap-2">
                    <x-brand-mark class="h-7 w-7" />
                    <span class="font-display text-2xl font-semibold tracking-tight text-brand-800 dark:text-white">Cerne</span>
                </a>
                <p class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">{{ $user?->isBroker() ? 'Painel do corretor' : 'Painel do consultor' }}</p>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
                @foreach ($navConsultor as [$route, $label, $curto, $icone])
                    @if ($route !== 'admin.users')
                        <a href="{{ route($route) }}" @class(['nav-item', 'nav-item-active' => request()->routeIs($route)])>
                            <x-nav-icon :name="$icone" />
                            <span>{{ $label }}</span>
                        </a>
                    @endif
                @endforeach

                @if ($user->isPlatformAdmin())
                    <a href="{{ route('admin.users') }}" class="nav-item mt-1">
                        <x-nav-icon name="admin" />
                        <span>Painel admin</span>
                    </a>
                @endif
            </nav>

            <div class="border-t border-brand-950/5 p-3 dark:border-white/10">
                <a href="{{ route('my-account') }}" @class(['nav-item mb-1', 'nav-item-active' => request()->routeIs('my-account')])>
                    <x-nav-icon name="users" />
                    <span>Minha conta</span>
                </a>

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
                        <button type="submit" class="btn-ghost px-2" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                        </button>
                    </form>
                    <livewire:notifications.notification-center direction="up" />
                </div>

                <div class="mt-2 flex justify-center">
                    <x-theme-switcher :current="$theme" />
                </div>
            </div>
        </aside>
    @elseif ($areaAdmin)
        {{-- Área de gestão da plataforma: toda conta, todo perfil. --}}
        <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-r border-brand-950/5 bg-white lg:flex dark:border-white/10 dark:bg-slate-900">
            <div class="px-6 pt-6 pb-4">
                <a href="{{ route('admin.users') }}" class="flex items-center gap-2">
                    <x-brand-mark class="h-7 w-7" />
                    <span class="font-display text-2xl font-semibold tracking-tight text-brand-800 dark:text-white">Cerne</span>
                </a>
                <p class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">Painel admin</p>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
                <a href="{{ route('admin.users') }}" @class(['nav-item', 'nav-item-active' => request()->routeIs('admin.users')])>
                    <x-nav-icon name="admin" />
                    <span>Contas e perfis</span>
                </a>
                <a href="{{ route('admin.banks') }}" @class(['nav-item', 'nav-item-active' => request()->routeIs('admin.banks')])>
                    <x-nav-icon name="cards" />
                    <span>Bancos</span>
                    @if ($bancosPendentes > 0)
                        <span class="badge ml-auto bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">{{ $bancosPendentes }}</span>
                    @endif
                </a>

                @if ($user->isLinkedProfessional())
                    <a href="{{ route($homeRouteProfissional) }}" class="nav-item mt-1">
                        <x-nav-icon name="invest" />
                        <span>{{ $user->isBroker() ? 'Seguros da carteira' : 'Painel da carteira' }}</span>
                    </a>
                @endif
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
                        <button type="submit" class="btn-ghost px-2" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                        </button>
                    </form>
                    <livewire:notifications.notification-center direction="up" />
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

        @if (session('impersonator_id'))
            <div class="flex items-center justify-between gap-3 bg-amber-500 px-4 py-2 text-sm text-amber-950">
                <span>Você está vendo como <strong>{{ $user->name }}</strong>.</span>
                <form method="POST" action="{{ route('admin.impersonate.stop') }}">
                    @csrf
                    <button type="submit" class="font-medium underline">Voltar para o painel admin</button>
                </form>
            </div>
        @endif

        {{-- Cabeçalho compacto: em desktop com perfil ele some (a lateral
             já cumpre o papel); sem perfil ou no celular ele fica. Dentro
             do perfil, leva a mesma cor de módulo da barra lateral (ver
             $corBarraModulo) — fora disso, continua no claro/escuro de
             sempre. --}}
        <header @class([
            'sticky top-0 z-20',
            'lg:hidden' => $mostraAsideDesktop,
            "$corBarraModulo border-b border-white/10" => $dentroDoPerfil,
            'border-b border-brand-950/5 bg-white/90 backdrop-blur dark:border-white/10 dark:bg-slate-900/90' => ! $dentroDoPerfil,
        ])>
            <div class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3 px-4">
                <a href="{{ route('dashboard') }}" @class([
                    'flex shrink-0 items-center gap-2 font-display text-xl font-semibold tracking-tight',
                    'text-white' => $dentroDoPerfil,
                    'text-brand-800 dark:text-white' => ! $dentroDoPerfil,
                ])>
                    <x-brand-mark class="h-6 w-6" />
                    Cerne
                </a>

                {{-- O sino fica FORA do trecho rolável, de propósito: um
                     ancestral com overflow-x força overflow-y a também virar
                     clipping (regra do CSS, não dá pra escapar só com
                     overflow-x sozinho) — o painel do sino, que abre pra
                     baixo, ficava cortado numa faixa de ~32px de altura e
                     nunca aparecia no celular (tocar no sino não fazia nada
                     visível). Com o sino fora do overflow-x-auto, sobra sem
                     ancestral que corte o painel. --}}
                <div class="flex min-w-0 flex-1 items-center justify-end gap-2">
                    {{-- min-w-0 é o que permite este grupo encolher menos que
                         seu conteúdo e rolar por dentro (overflow-x-auto) em
                         vez de estourar a largura da tela — consultor/admin
                         têm bem mais botões aqui do que o cliente comum, e
                         sem isto eles simplesmente somiam pra fora da tela no
                         celular. --}}
                    <div class="relative flex min-w-0 items-center gap-2 overflow-x-auto">
                        @if ($context->isConsultant())
                            <span @class([
                                'badge ring-1',
                                'bg-amber-500/10 text-amber-300 ring-amber-500/20' => $dentroDoPerfil,
                                'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => ! $dentroDoPerfil,
                            ])>{{ $user?->isBroker() ? 'corretor' : 'consultor' }}</span>
                        @endif

                        @if ($user?->isLinkedProfessional())
                            @foreach ($navConsultor as [$route, $label, $curto, $icone])
                                @if ($route !== 'admin.users')
                                    <a href="{{ route($route) }}" @class(['btn-ghost', '!text-white/60' => $dentroDoPerfil]) title="{{ $label }}">
                                        <x-nav-icon :name="$icone" class="h-4 w-4" />
                                        <span class="ml-1.5 hidden sm:inline">{{ $curto }}</span>
                                    </a>
                                @endif
                            @endforeach
                        @endif

                        @if ($user?->isPlatformAdmin())
                            <a href="{{ route('admin.users') }}" @class(['btn-ghost', '!text-white/60' => $dentroDoPerfil]) title="Painel admin">
                                <x-nav-icon name="admin" class="h-4 w-4" />
                                <span class="ml-1.5 hidden sm:inline">Admin</span>
                            </a>
                        @endif

                        <x-theme-switcher :current="$theme" />

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" @class(['btn-ghost', '!text-white/60' => $dentroDoPerfil]) title="Sair">
                                <x-nav-icon name="logout" class="h-4 w-4" />
                                <span class="ml-1.5 hidden sm:inline">Sair</span>
                            </button>
                        </form>
                    </div>

                    @auth
                        <div @class(['shrink-0', '[&>div>button]:!text-white/60' => $dentroDoPerfil])>
                            <livewire:notifications.notification-center />
                        </div>
                    @endauth
                </div>
            </div>
        </header>

        <main @class([
            'mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 sm:py-8 lg:px-10 lg:py-10',
            'pb-24 lg:pb-10' => $mostraAsideDesktop,  // espaço para a barra inferior no celular
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
    {{-- transform-gpu/will-change-transform: em alguns Android (Chrome,
         principalmente com o app instalado na tela inicial), um elemento
         fixed com backdrop-blur some durante a rolagem rápida — o
         navegador falha em recompor a camada em GPUs mais fracas. Forçar
         a própria camada de composição evita isso; não muda nada visual
         nos aparelhos que já funcionavam. --}}
    <div class="fixed inset-x-0 bottom-0 z-30 lg:hidden transform-gpu will-change-transform">
        {{-- Gaveta da categoria tocada: as telas dela, no mesmo desenho de
             sempre. Fecha tocando fora ou na aba de novo. --}}
        <div
            x-show="secao !== null"
            x-transition.opacity
            x-cloak
            @click="secao = null"
            class="fixed inset-0 bg-brand-950/30 backdrop-blur-sm"
        ></div>
        <div
            x-show="secao !== null"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-full"
            x-transition:enter-end="translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-y-0"
            x-transition:leave-end="translate-y-full"
            x-cloak
            @class(['relative rounded-t-3xl px-4 pt-3 pb-4 shadow-[0_-8px_32px_-8px_rgb(11_29_58_/_0.2)]', $corBarraModulo])
        >
            <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-white/20"></div>
            <div class="max-h-[60vh] overflow-y-auto">
                @foreach ($abasInferiores as $aba)
                    @unless ($aba['direta'])
                        <div x-show="secao === '{{ $aba['chave'] }}'" x-cloak class="pb-1">
                            <p @class(['px-1 pb-2 text-[10px] font-semibold tracking-wide uppercase', $aba['tint']])>{{ $aba['label'] }}</p>
                            <div class="grid grid-cols-4 gap-1">
                                @foreach ($aba['items'] as [$route, $label, $curto, $icone])
                                    <a href="{{ route($route) }}" @class(['tab-item-chrome rounded-xl py-3', 'tab-item-chrome-active bg-white/10' => request()->routeIs($route)])>
                                        <x-nav-icon :name="$icone" class="h-6 w-6" />
                                        <span>{{ $curto }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endunless
                @endforeach
            </div>
        </div>

        {{-- Abas --}}
        <nav @class(['relative flex border-t border-white/10', $corBarraModulo]) style="padding-bottom: env(safe-area-inset-bottom)">
            @foreach ($abasInferiores as $aba)
                @if ($aba['direta'])
                    <a href="{{ route($aba['items'][0][0]) }}" @class(['tab-item-chrome', 'tab-item-chrome-active' => $aba['ativa']])>
                        <x-nav-icon :name="$aba['icone']" class="h-6 w-6" />
                        <span>{{ $aba['label'] }}</span>
                    </a>
                @else
                    <button
                        type="button"
                        @click="secao = secao === '{{ $aba['chave'] }}' ? null : '{{ $aba['chave'] }}'"
                        @class(['tab-item-chrome', 'tab-item-chrome-active' => $aba['ativa']])
                        :class="secao === '{{ $aba['chave'] }}' ? 'text-white' : ''"
                        :aria-expanded="secao === '{{ $aba['chave'] }}'"
                    >
                        <x-nav-icon :name="$aba['icone']" class="h-6 w-6" />
                        <span>{{ $aba['label'] }}</span>
                    </button>
                @endif
            @endforeach
            <a href="{{ route('my-account') }}" @class(['tab-item-chrome', 'tab-item-chrome-active' => $naMinhaConta])>
                <x-nav-icon name="users" class="h-6 w-6" />
                <span>Minha conta</span>
            </a>
        </nav>
    </div>
@elseif ($areaConsultor)
    {{-- Poucas telas nesta área — cabem direto, sem gaveta "Mais". --}}
    <nav
        class="fixed inset-x-0 bottom-0 z-30 flex overflow-x-auto border-t border-brand-950/5 bg-white/95 backdrop-blur transform-gpu will-change-transform lg:hidden dark:border-white/10 dark:bg-slate-900/95"
        style="padding-bottom: env(safe-area-inset-bottom)"
    >
        @foreach ($navConsultor as [$route, $label, $curto, $icone])
            <a href="{{ route($route) }}" @class(['tab-item', 'tab-item-active' => request()->routeIs($route)])>
                <x-nav-icon :name="$icone" class="h-6 w-6" />
                <span>{{ $curto }}</span>
            </a>
        @endforeach
        <a href="{{ route('my-account') }}" @class(['tab-item', 'tab-item-active' => request()->routeIs('my-account')])>
            <x-nav-icon name="users" class="h-6 w-6" />
            <span>Minha conta</span>
        </a>
    </nav>
@elseif ($areaAdmin)
    <nav
        class="fixed inset-x-0 bottom-0 z-30 flex overflow-x-auto border-t border-brand-950/5 bg-white/95 backdrop-blur transform-gpu will-change-transform lg:hidden dark:border-white/10 dark:bg-slate-900/95"
        style="padding-bottom: env(safe-area-inset-bottom)"
    >
        @foreach ($navAdmin as [$route, $label, $curto, $icone])
            <a href="{{ route($route) }}" @class(['tab-item', 'tab-item-active' => request()->routeIs($route)])>
                <x-nav-icon :name="$icone" class="h-6 w-6" />
                <span>{{ $curto }}</span>
            </a>
        @endforeach
    </nav>
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
