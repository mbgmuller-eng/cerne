@php
    // Telas largas (quadros em colunas) pedem a largura toda; as demais usam a medida normal, que cresce em monitores grandes.
    $wide = $wide ?? false;
    /** @var \App\Support\ProfileContext $context */
    $context = app(\App\Support\ProfileContext::class);
    $profile = $context->profile();
    $user = auth()->user();
    // Tela com tutorial ganha o botão "?" e o cartão de primeira visita (ver App\Support\Tutorials\TutorialCatalog).
    $rotaAtual = request()->route()?->getName();
    $temTutorial = \App\Support\Tutorials\TutorialCatalog::has($rotaAtual);

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
        ['debts.index', 'Dívidas', 'Dívidas', 'debt'],
        ['investments.index', 'Investimentos', 'Invest.', 'invest'],
        ['goals.index', 'Objetivos', 'Metas', 'target'],
        ['documents.index', 'Importar', 'Importar', 'upload'],
        // Cadastros e regras ficam juntos, no fim do grupo (ver 'cadastrosDe' em $navSections): é o que se mexe de vez em quando, não o que se olha todo dia.
        ['accounts.index', 'Contas & Cartões', 'Contas', 'cards'],
        ['categorization-rules.index', 'Regras de categorização', 'Regras', 'tag'],
        ['budget.index', 'Regra de orçamento', 'Regra', 'pie'],
    ];
    // Acompanhamento: só quem tem vínculo ativo com um consultor financeiro (cliente), ou o consultor dentro do perfil de um
    // cliente dele. Sem consultor, a área não existe.
    if ($profile && app(\App\Services\EngagementAccess::class)->viewer() !== null) {
        array_splice($navFinancas, array_search('investments.index', array_column($navFinancas, 0), true), 0, [['engagement.index', 'Acompanhamento', 'Consultoria', 'contact']]);
    }
    // Calculado DEPOIS das inserções acima: o Acompanhamento empurra os cadastros para baixo.
    $cadastrosDe = array_search('accounts.index', array_column($navFinancas, 0), true);
    $navSeguros = [
        ['insurance.index', 'Apólices', 'Seguros', 'shield'],
    ];

    $navSaude = [
        ['health.activities.index', 'Atividades', 'Atividades', 'dumbbell'],
        ['health.card.index', 'Ficha de Saúde', 'Ficha', 'heart'],
        ['health.appointments.index', 'Agenda de Saúde', 'Agenda', 'calendar'],
        ['health.routines.index', 'Check-ups e rotinas', 'Check-ups', 'heart'],
        ['health.followups.index', 'Acompanhamentos', 'Acompanhar', 'contact'],
        ['health.exams.index', 'Exames', 'Exames', 'bills'],
        ['health.professionals.index', 'Médicos e profissionais', 'Médicos', 'users'],
        ['health.care.index', 'Cuidados e itens', 'Cuidados', 'tag'],
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
            ['label' => 'Finanças', 'items' => $navFinancas, 'cadastrosDe' => $cadastrosDe],
            ['label' => 'Seguros', 'items' => $navSeguros],
            ['label' => 'Documentos', 'items' => $navDocumentos],
            $context->isConsultant() ? null : ['label' => 'Saúde', 'items' => $navSaude],
        ]));

    // Dentro do perfil de uma pessoa acompanhada (cuidador, ou o dono dela) só existem saúde e documentos: o resto é finança, que ali não há.
    $emPerfilDeCuidado = $profile && ($context->isCaregiver() || $profile->isCare());
    // A própria pessoa acompanhada, já com login: o espaço é dela, não "alguém que ela acompanha".
    $souAPessoa = $emPerfilDeCuidado && $context->member()?->user_id === $user?->id;
    if ($emPerfilDeCuidado) {
        // Quem acompanha pode ter só a saúde, só as finanças (leitura) ou as duas: o menu mostra o que ele pode abrir.
        $comSaude = ! $context->isCaregiver() || $context->hasHealthAccess();
        $comFinancas = $context->isCaregiver() && $context->hasFinanceAccess() && ! $profile->isCare();
        $itemPessoas = ['care.index', $souAPessoa ? 'Quem tem acesso' : 'Pessoas e acessos', $souAPessoa ? 'Acessos' : 'Pessoas', 'contact'];

        $navSections = array_values(array_filter([
            $comSaude ? ['label' => 'Saúde', 'items' => [
                ['health.card.index', 'Ficha de Saúde', 'Ficha', 'heart'],
                ['health.appointments.index', 'Agenda de Saúde', 'Agenda', 'calendar'],
                ['health.routines.index', 'Check-ups e rotinas', 'Check-ups', 'heart'],
                ['health.followups.index', 'Acompanhamentos', 'Acompanhar', 'contact'],
                ['health.exams.index', 'Exames', 'Exames', 'bills'],
                ['health.professionals.index', 'Médicos e profissionais', 'Médicos', 'users'],
                ['health.care.index', 'Cuidados e itens', 'Cuidados', 'tag'],
                $itemPessoas,
            ]] : null,
            $comFinancas ? ['label' => 'Finanças', 'items' => array_values(array_filter([
                ['family.index', 'Finanças da família', 'Finanças', 'home'],
                $comSaude ? null : $itemPessoas,
            ]))] : null,
            $comSaude ? ['label' => 'Documentos', 'items' => $navDocumentos] : null,
        ]));
    } elseif ($user && \App\Services\CareProfileService::enabled()) {
        // "Pessoas que acompanho" entra na seção de Saúde de quem pode cadastrar alguém (recurso ligado). Quem só cuida, sem plano próprio,
        // chega pela lista (care.index) e pelo banner.
        $navSections = array_map(function (array $secao) {
            if ($secao['label'] === 'Saúde') {
                $secao['items'][] = ['care.index', 'Pessoas e acessos', 'Pessoas', 'contact'];
            }

            return $secao;
        }, $navSections);
    }

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

    $naMinhaConta = request()->routeIs('my-account');
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
    // Qualquer tela do app de um profissional sem cliente aberto é DELE (Pessoas e acessos, Minha conta, Assinatura...): sem isto ficava sem barra
    // inferior e sem lateral, e o celular não tinha por onde navegar.
    $areaConsultor = request()->routeIs('consultant.*')
        || ($profile === null && $user?->isLinkedProfessional() && ! request()->routeIs('admin.*'));
    $areaAdmin = request()->routeIs('admin.*');
    $dentroDoPerfil = $profile && ! $areaConsultor && ! $areaAdmin;
    $mostraAsideDesktop = $dentroDoPerfil || $areaConsultor || $areaAdmin;
    // A busca percorre as telas financeiras do perfil: fica fora para corretor (só Seguros) e para quem está num perfil de cuidado.
    $podeBuscar = $dentroDoPerfil && ! $emPerfilDeCuidado && ! $user?->isBroker();

    // Menu do profissional, em grupos por tipo de trabalho:
    //  - Consultoria: carteira e investimentos, tela financeira (só consultor);
    //  - Seguros: o corretor trabalha aqui e o consultor também enxerga;
    //  - Gestão: datas importantes e leads, rotina comum a todo profissional
    //    (inclusive os de saúde, quando chegarem);
    //  - Plataforma: o painel admin, só pra conta de administrador, fora dos
    //    grupos de propósito: não é parte do trabalho com clientes.
    $secoesProfissional = array_values(array_filter([
        $user?->isBroker() ? null : [
            'chave' => 'consultoria', 'label' => 'Consultoria', 'icone' => 'invest',
            'items' => [
                ['consultant.portfolio', 'Painel da carteira', 'Carteira', 'invest'],
                ['consultant.portfolio.investments', 'Investimentos da carteira', 'Invest.', 'flow'],
                ['consultant.portfolio.debts', 'Dívidas da carteira', 'Dívidas', 'debt'],
                ['consultant.portfolio.budget', 'Regra da carteira', 'Regra', 'pie'],
                ['consultant.week', 'Minha semana', 'Semana', 'calendar'],
                ['consultant.plan-templates', 'Modelos de roteiro', 'Modelos', 'bills'],
                ['consultant.budget-templates', 'Modelos de regra', 'Modelos', 'pie'],
            ],
        ],
        [
            'chave' => 'seguros', 'label' => 'Seguros', 'icone' => 'shield',
            'items' => [['consultant.portfolio.insurance', 'Seguros da carteira', 'Seguros', 'shield']],
        ],
        [
            'chave' => 'gestao', 'label' => 'Gestão', 'icone' => 'calendar',
            'items' => [
                ['consultant.calendar', 'Agenda', 'Agenda', 'calendar'],
                ['consultant.income', 'Recebimentos', 'Receb.', 'coins'],
                ['consultant.portfolio.important-dates', 'Datas importantes', 'Datas', 'bell'],
                ['consultant.leads', 'Leads', 'Leads', 'contact'],
            ],
        ],
    ]));
    $secaoPlataforma = $user?->isPlatformAdmin() ? [
        'chave' => 'plataforma', 'label' => 'Plataforma', 'icone' => 'admin',
        'items' => [['admin.users', 'Painel admin', 'Admin', 'admin']],
    ] : null;

    // Lista achatada: o cabeçalho compacto e o rodapé do menu de dentro do
    // perfil só precisam dos atalhos, sem agrupamento.
    $navConsultor = collect($secoesProfissional)->flatMap(fn (array $secao) => $secao['items'])->all();
    if ($secaoPlataforma) {
        $navConsultor[] = $secaoPlataforma['items'][0];
    }

    // Barra inferior do profissional no celular: uma aba por grupo, gaveta
    // quando o grupo tem mais de uma tela (mesmo desenho da barra do
    // cliente, ver $abasInferiores).
    $abasProfissional = collect([...$secoesProfissional, ...($secaoPlataforma ? [$secaoPlataforma] : [])])
        ->map(fn (array $secao) => $secao + [
            'ativa' => collect($secao['items'])->contains(fn (array $item) => request()->routeIs($item[0])),
            'direta' => count($secao['items']) === 1,
        ])
        ->all();
    // Quem é profissional E administrador precisa de um caminho de volta
    // óbvio pra área de trabalho dele: fica em destaque, fora da lista de
    // telas da plataforma (que são de outra natureza).
    $voltarAoPainel = $user?->isLinkedProfessional() ? [
        'rota' => $user->isBroker() ? 'consultant.portfolio.insurance' : 'consultant.portfolio',
        'nome' => $user->isBroker() ? 'Painel do corretor' : 'Painel do consultor',
        'curto' => $user->isBroker() ? 'Seguros' : 'Consultoria',
    ] : null;
    // "Página inicial" de cada profissional — corretor não tem Painel da
    // carteira (é tela financeira), a dele é Seguros da carteira.
    $homeRouteProfissional = $user?->isBroker() ? 'consultant.portfolio.insurance' : 'consultant.portfolio';

    // Só conta se for mesmo relevante — poupa uma query em toda página
    // pra quem não é admin.
    $bancosPendentes = $user?->isPlatformAdmin() ? \App\Models\Bank::withoutTaxonomyScope()->pending()->count() : 0;
    $seguradorasPendentes = $user?->isPlatformAdmin() ? \App\Models\Insurer::withoutTaxonomyScope()->pending()->count() : 0;
    $exerciciosPendentes = $user?->isPlatformAdmin() ? \App\Models\GymExerciseSuggestion::query()->pending()->count() : 0;
    // Erros da plataforma ainda sem resolver: o número que aparece em "Erros" no menu do administrador.
    $errosAbertos = $user?->isPlatformAdmin() ? \App\Models\ErrorLog::query()->open()->count() : 0;
    // Cadastros da plataforma (admin): catálogos compartilhados, cada um com a
    // fila de sugestões dos usuários. Última posição = pendências.
    $cadastrosAdmin = [
        ['admin.banks', 'Bancos', 'Bancos', 'cards', $bancosPendentes],
        ['admin.insurers', 'Seguradoras', 'Seguradoras', 'shield', $seguradorasPendentes],
        ['admin.exercises', 'Exercícios', 'Exercícios', 'dumbbell', $exerciciosPendentes],
    ];

    // Menu lateral do celular (no lugar da barra inferior, que tinha uma gaveta por categoria): as MESMAS telas da barra lateral do desktop, em
    // grupos. Cada item: [rota, rótulo, ícone, selo ou null, tipo do selo ('erro' | 'pendente' | null)].
    $gavetaSecoes = [];
    if ($dentroDoPerfil) {
        foreach ($navSections as $secao) {
            $gavetaSecoes[] = [
                'label' => $secao['label'],
                'tint' => $secao['tint'],
                'cadastrosDe' => $secao['cadastrosDe'] ?? null,
                'items' => array_map(fn (array $i) => [$i[0], $i[1], $i[3], null, null], $secao['items']),
            ];
        }
    } elseif ($areaConsultor) {
        foreach ([...$secoesProfissional, ...($secaoPlataforma ? [$secaoPlataforma] : [])] as $secao) {
            $gavetaSecoes[] = [
                'label' => $secao['label'],
                'tint' => 'text-slate-400 dark:text-slate-500',
                'cadastrosDe' => null,
                'items' => array_map(fn (array $i) => [$i[0], $i[1], $i[3], null, null], $secao['items']),
            ];
        }
    } elseif ($areaAdmin) {
        $gavetaSecoes[] = [
            'label' => 'Plataforma',
            'tint' => 'text-slate-400 dark:text-slate-500',
            'cadastrosDe' => null,
            'items' => [
                ['admin.users', 'Contas e perfis', 'admin', null, null],
                ['admin.errors', 'Erros', 'alert', $errosAbertos > 0 ? $errosAbertos : null, 'erro'],
                ['admin.access-log', 'Acessos', 'lock', null, null],
                ['admin.incidents', 'Incidentes', 'shield', null, null],
                ['admin.rules', 'Revisão das regras', 'dumbbell', null, null],
            ],
        ];
        $gavetaSecoes[] = [
            'label' => 'Cadastros',
            'tint' => 'text-slate-400 dark:text-slate-500',
            'cadastrosDe' => null,
            'items' => array_map(fn (array $i) => [$i[0], $i[1], $i[3], $i[4] > 0 ? $i[4] : null, 'pendente'], $cadastrosAdmin),
        ];
    }

    // Áreas do menu lateral do computador, que a pessoa abre e fecha. A área da tela de agora aparece aberta mesmo que esteja fechada (sem
    // mudar a escolha guardada). Cada barra tem as próprias chaves: fechar "Seguros" no perfil não fecha o "Seguros" do painel do profissional.
    $chaveDaSecao = fn (string $barra, string $nome) => $barra.'-'.\Illuminate\Support\Str::slug($nome);
    // Vale para as telas de dentro de cada item (o detalhe de uma dívida é Finanças). "Importar" é exato: o prefixo dele também é o dos Documentos.
    $secaoTemATelaAtual = fn (array $itens) => collect($itens)->contains(fn (array $i) => request()->routeIs(
        $i[0], $i[0] === 'documents.index' ? $i[0] : \Illuminate\Support\Str::replaceEnd('.index', '.*', $i[0]),
        ...\App\Support\MenuRoutes::activePatterns($i[0]),
    ));
    $chaveSecaoAtiva = null;
    if ($dentroDoPerfil) {
        foreach ($navSections as $secao) {
            if ($secaoTemATelaAtual($secao['items'])) {
                $chaveSecaoAtiva = $chaveDaSecao('perfil', $secao['label']);
                break;
            }
        }
    } elseif ($areaConsultor) {
        foreach ([...$secoesProfissional, ...($secaoPlataforma ? [$secaoPlataforma] : [])] as $secao) {
            if (collect($secao['items'])->contains(fn (array $i) => request()->routeIs($i[0]))) {
                $chaveSecaoAtiva = $chaveDaSecao('pro', $secao['label']);
                break;
            }
        }
    } elseif ($areaAdmin) {
        $chaveSecaoAtiva = request()->routeIs(...array_column($cadastrosAdmin, 0)) ? 'admin-cadastros' : 'admin-plataforma';
    }

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
    {{-- Menu lateral recolhido: preferência deste navegador, aplicada ANTES do primeiro desenho para a barra não piscar larga e encolher. --}}
    <script>
        (function () {
            try {
                if (localStorage.getItem('cerne.menu') === 'recolhido') document.documentElement.classList.add('menu-recolhido');
            } catch (e) {}
        })();
    </script>
    {{-- Áreas fechadas do menu lateral: também antes do primeiro desenho. Um <style> com uma regra por área fechada (o resources/js/app.js o reescreve). --}}
    <script>
        window.cerneSecoes = {
            ler: function () {
                try {
                    var v = JSON.parse(localStorage.getItem('cerne.menu.secoes') || '[]');
                    return Array.isArray(v) ? v.filter(function (k) { return /^[a-z0-9-]+$/.test(k); }) : [];
                } catch (e) { return []; }
            },
            estilo: function (fechadas, aberta) {
                var s = document.getElementById('cerne-secoes') || document.head.appendChild(Object.assign(document.createElement('style'), { id: 'cerne-secoes' }));
                s.textContent = fechadas.filter(function (k) { return k !== aberta; }).map(function (k) {
                    var alvo = 'html:not(.menu-recolhido) [data-lateral] [data-secao="' + k + '"]';
                    return alvo + ' > [data-secao-itens]{display:none}' + alvo + ' .lateral-seta{transform:rotate(-90deg)}';
                }).join('');
            }
        };
        window.cerneSecoes.estilo(window.cerneSecoes.ler(), @json($chaveSecaoAtiva));
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
<body
    class="h-full bg-paper text-slate-800 antialiased dark:text-slate-200"
    x-data="{ menu: false, topo: false }"
    x-effect="document.documentElement.classList.toggle('overflow-hidden', menu)"
    @resize.window="if (window.innerWidth >= 1024) menu = false"
    @scroll.window.passive="topo = window.scrollY > 600"
@if ($podeBuscar)
    @keydown.window="if (event.key === '/' && ! event.ctrlKey && ! event.metaKey && ! event.altKey && ! ['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName) && ! document.activeElement?.isContentEditable) { event.preventDefault(); window.Livewire?.navigate ? Livewire.navigate('{{ route('search.index') }}') : (window.location = '{{ route('search.index') }}') }"
@endif
>

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
        <aside data-lateral @class(['sticky top-0 z-30 hidden h-screen w-64 shrink-0 transition-[width] duration-200 flex-col lg:flex dark:bg-slate-900', $corBarraModulo])>
            <div class="lateral-cab px-6 pt-6 pb-4">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                        <x-brand-mark class="h-7 w-7" />
                        <span class="lateral-texto font-display text-2xl font-semibold tracking-tight text-white">Cerne</span>
                    </a>
                    <button type="button" data-alternar-menu aria-label="Recolher ou abrir o menu" title="Recolher ou abrir o menu" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition text-white/60 hover:bg-white/10 hover:text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>
                </div>
                <p class="lateral-texto mt-1 truncate text-xs text-white/50">{{ $profile->profile_name }}</p>
            </div>

            @if ($voltarAoPainel)
                <a href="{{ route($voltarAoPainel['rota']) }}" class="mx-3 mb-3 flex items-center gap-3 rounded-xl bg-white/10 px-3 py-2.5 text-white transition hover:bg-white/15">
                    <x-nav-icon name="back" />
                    <span class="lateral-texto min-w-0 leading-tight">
                        <span class="block text-[10px] font-semibold tracking-wide text-white/50 uppercase">Voltar para</span>
                        <span class="block truncate text-sm font-medium">{{ $voltarAoPainel['nome'] }}</span>
                    </span>
                </a>
            @endif

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3">
                @foreach ($navSections as $secao)
                    @php $chave = $chaveDaSecao('perfil', $secao['label']); @endphp
                    <div @class(['lateral-secao', 'pt-4' => ! $loop->first]) data-secao="{{ $chave }}" @if ($chave === $chaveSecaoAtiva) data-ativa @endif>
                        <x-lateral-titulo :chave="$chave" :rotulo="$secao['label']" :class="$secao['tint']">
                            @if ($secao['emBreve'] ?? false)
                                <span class="ml-1 rounded bg-white/10 px-1.5 py-0.5 text-[9px] font-normal tracking-normal text-white/50 normal-case">em breve</span>
                            @endif
                        </x-lateral-titulo>
                        <div class="mt-1 space-y-0.5" data-secao-itens>
                            @foreach ($secao['items'] as [$route, $label, $curto, $icone])
                                @if (isset($secao['cadastrosDe']) && $loop->index === $secao['cadastrosDe'])
                                    <p class="lateral-titulo px-3 pt-4 pb-1 text-[10px] font-semibold tracking-wide text-white/40 uppercase">Cadastros e regras</p>
                                @endif
                                <a href="{{ route($route) }}" title="{{ $label }}" @class(['nav-item-chrome', 'nav-item-chrome-active '.$corAtivoEscuroDesktop => request()->routeIs(...\App\Support\MenuRoutes::activePatterns($route))])>
                                    <x-nav-icon :name="$icone" />
                                    <span class="lateral-texto">{{ $label }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                {{-- Os atalhos do profissional ficam recolhidos: o menu do cliente é o que se usa aqui, e o do profissional
                     inteiro no rodapé espremia o do cliente numa faixa minúscula com rolagem. --}}
                @if ($user->isLinkedProfessional() || $user->isPlatformAdmin())
                    <div class="pt-4" x-data="{ aberto: false }">
                        <button type="button" @click="aberto = ! aberto" :aria-expanded="aberto" class="flex w-full items-center justify-between px-3 pb-1 text-[10px] font-semibold tracking-wide text-white/50 uppercase transition hover:text-white/80">
                            <span class="lateral-texto">Área do profissional</span>
                            <svg class="h-3 w-3 transition" :class="aberto ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                        </button>
                        <div x-show="aberto" x-cloak class="space-y-0.5">
                            @if ($user->isLinkedProfessional())
                                @foreach ($navConsultor as [$route, $label, $curto, $icone])
                                    @if ($route !== 'admin.users')
                                        <a href="{{ route($route) }}" title="{{ $label }}" class="nav-item-chrome">
                                            <x-nav-icon :name="$icone" />
                                            <span class="lateral-texto">{{ $label }}</span>
                                        </a>
                                    @endif
                                @endforeach
                            @endif
                            @if ($user->isPlatformAdmin())
                                <a href="{{ route('admin.users') }}" title="Painel admin" class="nav-item-chrome">
                                    <x-nav-icon name="admin" />
                                    <span class="lateral-texto">Painel admin</span>
                                </a>
                            @endif
                        </div>
                    </div>
                @endif
            </nav>

            <div class="border-t border-white/10 p-3">
                @if ($context->isConsultant())
                    <div class="lateral-texto mb-2 rounded-xl bg-amber-500/10 px-3 py-2 text-xs text-amber-300 ring-1 ring-amber-500/20">
                        Você está vendo este perfil <span class="font-medium">como {{ $user?->isBroker() ? 'corretor' : 'consultor' }}</span>.
                    </div>
                @endif

                <a href="{{ route('home') }}" title="Site do Cerne" class="nav-item-chrome mb-1" data-site-do-cerne>
                    <x-nav-icon name="site" />
                    <span class="lateral-texto">Site do Cerne</span>
                </a>

                <a href="{{ route('my-account') }}" title="Minha conta" @class(['nav-item-chrome mb-1', 'nav-item-chrome-active '.$corAtivoEscuroDesktop => request()->routeIs('my-account')])>
                    <x-nav-icon name="users" />
                    <span class="lateral-texto">Minha conta</span>
                </a>

                <div class="lateral-usuario flex items-center gap-3 px-2 py-1.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-white">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </div>
                    <div class="lateral-texto min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-white">{{ $user->name }}</p>
                        <p class="truncate text-xs text-white/50">{{ $user->email }}</p>
                    </div>
                </div>

                {{-- Segunda linha do rodapé: Sair à esquerda e as ferramentas (tutorial, notificações) à direita. Tudo numa linha só com o nome
                     espremia o nome e o e-mail até virarem "M…". --}}
                <div class="lateral-rodape mt-1 flex items-center justify-between px-1">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost !text-white/60 px-2" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                            <span class="lateral-texto ml-1.5 text-xs">Sair</span>
                        </button>
                    </form>
                    <div class="flex items-center gap-1">
                        @if ($podeBuscar)
                            <a href="{{ route('search.index') }}" wire:navigate aria-label="Buscar (atalho: /)" title="Buscar (atalho: /)" data-busca-link class="flex h-9 w-9 items-center justify-center rounded-xl text-white/60 transition hover:bg-white/10 hover:text-white">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                            </a>
                        @endif
                        @if ($temTutorial)
                            <x-help-button class="!text-white/60" />
                        @endif
                        {{-- O botão do sino é do componente compartilhado
                             (usado no cabeçalho também, com fundo claro) — o
                             seletor aqui força a cor certa só nesta instância,
                             sem mexer no componente em si. --}}
                        <div class="[&>div>button]:!text-white/60">
                            <livewire:notifications.notification-center direction="up" />
                        </div>
                    </div>
                </div>

                <div class="lateral-tema mt-2 flex justify-center">
                    <x-theme-switcher :current="$theme" />
                </div>
            </div>
        </aside>
    @elseif ($areaConsultor)
        {{-- Área de gestão do consultor: carteira e clientes, não um perfil. --}}
        <aside data-lateral class="sticky top-0 z-30 hidden h-screen w-64 shrink-0 transition-[width] duration-200 flex-col border-r border-brand-950/5 bg-white lg:flex dark:border-white/10 dark:bg-slate-900">
            <div class="lateral-cab px-6 pt-6 pb-4">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ route($homeRouteProfissional) }}" class="flex items-center gap-2">
                        <x-brand-mark class="h-7 w-7" />
                        <span class="lateral-texto font-display text-2xl font-semibold tracking-tight text-brand-800 dark:text-white">Cerne</span>
                    </a>
                    <button type="button" data-alternar-menu aria-label="Recolher ou abrir o menu" title="Recolher ou abrir o menu" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>
                </div>
                <p class="lateral-texto mt-1 truncate text-xs text-slate-500 dark:text-slate-400">{{ $user?->isBroker() ? 'Painel do corretor' : 'Painel do consultor' }}</p>
            </div>

            <nav class="flex flex-1 flex-col overflow-y-auto px-3">
                @foreach ($secoesProfissional as $secao)
                    @php $chave = $chaveDaSecao('pro', $secao['label']); @endphp
                    <div @class(['lateral-secao', 'pt-5' => ! $loop->first]) data-secao="{{ $chave }}" @if ($chave === $chaveSecaoAtiva) data-ativa @endif>
                        <x-lateral-titulo :chave="$chave" :rotulo="$secao['label']" class="text-slate-400 dark:text-slate-500" />
                        <div class="mt-1 space-y-0.5" data-secao-itens>
                            @foreach ($secao['items'] as [$route, $label, $curto, $icone])
                                <a href="{{ route($route) }}" title="{{ $label }}" @class(['nav-item', 'nav-item-active' => request()->routeIs($route)])>
                                    <x-nav-icon :name="$icone" />
                                    <span class="lateral-texto">{{ $label }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                {{-- Só a conta de administrador: separado dos grupos e no fim. --}}
                @if ($secaoPlataforma)
                    @php $chave = $chaveDaSecao('pro', $secaoPlataforma['label']); @endphp
                    <div class="mt-auto pt-6">
                        <div class="border-t border-brand-950/5 pt-4 dark:border-white/10" data-secao="{{ $chave }}" @if ($chave === $chaveSecaoAtiva) data-ativa @endif>
                            <x-lateral-titulo :chave="$chave" :rotulo="$secaoPlataforma['label']" class="text-slate-400 dark:text-slate-500" />
                            <div class="mt-1 space-y-0.5" data-secao-itens>
                                @foreach ($secaoPlataforma['items'] as [$route, $label, $curto, $icone])
                                    <a href="{{ route($route) }}" title="{{ $label }}" class="nav-item">
                                        <x-nav-icon :name="$icone" />
                                        <span class="lateral-texto">{{ $label }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </nav>

            <div class="border-t border-brand-950/5 p-3 dark:border-white/10">
                <a href="{{ route('home') }}" title="Site do Cerne" class="nav-item mb-1" data-site-do-cerne>
                    <x-nav-icon name="site" />
                    <span class="lateral-texto">Site do Cerne</span>
                </a>

                <a href="{{ route('my-account') }}" title="Minha conta" @class(['nav-item mb-1', 'nav-item-active' => request()->routeIs('my-account')])>
                    <x-nav-icon name="users" />
                    <span class="lateral-texto">Minha conta</span>
                </a>

                <div class="lateral-usuario flex items-center gap-3 px-2 py-1.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800 dark:bg-white/10 dark:text-white">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </div>
                    <div class="lateral-texto min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $user->name }}</p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                    </div>
                </div>

                <div class="lateral-rodape mt-1 flex items-center justify-between px-1">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost px-2" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                            <span class="lateral-texto ml-1.5 text-xs">Sair</span>
                        </button>
                    </form>
                    <div class="flex items-center gap-1">
                        @if ($temTutorial)
                            <x-help-button />
                        @endif
                        <livewire:notifications.notification-center direction="up" />
                    </div>
                </div>

                <div class="lateral-tema mt-2 flex justify-center">
                    <x-theme-switcher :current="$theme" />
                </div>
            </div>
        </aside>
    @elseif ($areaAdmin)
        {{-- Área de gestão da plataforma: toda conta, todo perfil. --}}
        <aside data-lateral class="sticky top-0 z-30 hidden h-screen w-64 shrink-0 transition-[width] duration-200 flex-col border-r border-brand-950/5 bg-white lg:flex dark:border-white/10 dark:bg-slate-900">
            <div class="lateral-cab px-6 pt-6 pb-4">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ route('admin.users') }}" class="flex items-center gap-2">
                        <x-brand-mark class="h-7 w-7" />
                        <span class="lateral-texto font-display text-2xl font-semibold tracking-tight text-brand-800 dark:text-white">Cerne</span>
                    </a>
                    <button type="button" data-alternar-menu aria-label="Recolher ou abrir o menu" title="Recolher ou abrir o menu" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>
                </div>
                <p class="lateral-texto mt-1 truncate text-xs text-slate-500 dark:text-slate-400">Painel admin</p>
            </div>

            <nav class="flex flex-1 flex-col overflow-y-auto px-3">
                @if ($voltarAoPainel)
                    <a href="{{ route($voltarAoPainel['rota']) }}" class="mb-6 flex items-center gap-3 rounded-xl border border-brand-950/10 bg-slate-50 px-3 py-2.5 text-brand-800 transition hover:bg-slate-100 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10">
                        <x-nav-icon name="back" />
                        <span class="lateral-texto min-w-0 leading-tight">
                            <span class="block text-[10px] font-semibold tracking-wide text-slate-400 uppercase dark:text-slate-500">Voltar para</span>
                            <span class="block truncate text-sm font-medium">{{ $voltarAoPainel['nome'] }}</span>
                        </span>
                    </a>
                @endif

                <div data-secao="admin-plataforma" @if ($chaveSecaoAtiva === 'admin-plataforma') data-ativa @endif>
                <x-lateral-titulo chave="admin-plataforma" rotulo="Plataforma" class="text-slate-400 dark:text-slate-500" />
                <div class="mt-1 space-y-0.5" data-secao-itens>
                    <a href="{{ route('admin.users') }}" title="Contas e perfis" @class(['nav-item', 'nav-item-active' => request()->routeIs('admin.users')])>
                        <x-nav-icon name="admin" />
                        <span class="lateral-texto">Contas e perfis</span>
                    </a>
                    <a href="{{ route('admin.errors') }}" title="Erros" @class(['nav-item', 'nav-item-active' => request()->routeIs('admin.errors')])>
                        <x-nav-icon name="alert" />
                        <span class="lateral-texto">Erros</span>
                        @if ($errosAbertos > 0)
                            <span class="badge ml-auto bg-red-50 text-red-800 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20">{{ $errosAbertos }}</span>
                        @endif
                    </a>
                    <a href="{{ route('admin.access-log') }}" title="Acessos" @class(['nav-item', 'nav-item-active' => request()->routeIs('admin.access-log')])>
                        <x-nav-icon name="lock" />
                        <span class="lateral-texto">Acessos</span>
                    </a>
                    <a href="{{ route('admin.incidents') }}" title="Incidentes" @class(['nav-item', 'nav-item-active' => request()->routeIs('admin.incidents')])>
                        <x-nav-icon name="shield" />
                        <span class="lateral-texto">Incidentes</span>
                    </a>
                    <a href="{{ route('admin.rules') }}" title="Revisão das regras" @class(['nav-item', 'nav-item-active' => request()->routeIs('admin.rules')])>
                        <x-nav-icon name="dumbbell" />
                        <span class="lateral-texto">Revisão das regras</span>
                    </a>
                </div>
                </div>

                <div class="mt-5" data-secao="admin-cadastros" @if ($chaveSecaoAtiva === 'admin-cadastros') data-ativa @endif>
                <x-lateral-titulo chave="admin-cadastros" rotulo="Cadastros" class="text-slate-400 dark:text-slate-500" />
                <div class="mt-1 space-y-0.5" data-secao-itens>
                    @foreach ($cadastrosAdmin as [$route, $label, $curto, $icone, $pendentes])
                        <a href="{{ route($route) }}" title="{{ $label }}" @class(['nav-item', 'nav-item-active' => request()->routeIs($route)])>
                            <x-nav-icon :name="$icone" />
                            <span class="lateral-texto">{{ $label }}</span>
                            @if ($pendentes > 0)
                                <span class="badge ml-auto bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">{{ $pendentes }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
                </div>
            </nav>

            <div class="border-t border-brand-950/5 p-3 dark:border-white/10">
                <a href="{{ route('home') }}" title="Site do Cerne" class="nav-item mb-1" data-site-do-cerne>
                    <x-nav-icon name="site" />
                    <span class="lateral-texto">Site do Cerne</span>
                </a>

                <a href="{{ route('my-account') }}" title="Minha conta" @class(['nav-item mb-1', 'nav-item-active' => request()->routeIs('my-account')])>
                    <x-nav-icon name="users" />
                    <span class="lateral-texto">Minha conta</span>
                </a>
                <div class="lateral-usuario flex items-center gap-3 px-2 py-1.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800 dark:bg-white/10 dark:text-white">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </div>
                    <div class="lateral-texto min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $user->name }}</p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                    </div>
                </div>

                <div class="lateral-rodape mt-1 flex items-center justify-between px-1">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost px-2" title="Sair">
                            <x-nav-icon name="logout" class="h-4 w-4" />
                            <span class="lateral-texto ml-1.5 text-xs">Sair</span>
                        </button>
                    </form>
                    <div class="flex items-center gap-1">
                        @if ($temTutorial)
                            <x-help-button />
                        @endif
                        <livewire:notifications.notification-center direction="up" />
                    </div>
                </div>

                <div class="lateral-tema mt-2 flex justify-center">
                    <x-theme-switcher :current="$theme" />
                </div>
            </div>
        </aside>
    @endif

    {{-- ============================================================
         Área principal
         ============================================================ --}}
    <div class="flex min-w-0 flex-1 flex-col">

        @if ($emPerfilDeCuidado)
            <div class="flex flex-wrap items-center justify-between gap-3 bg-saude-800 px-4 py-2 text-sm text-white">
                <span>
                    @if ($souAPessoa)
                        Este é o <strong>seu espaço de saúde</strong>.
                    @else
                        Você está acompanhando <strong>{{ $context->isCaregiver() ? $context->followedNames() : $profile->profile_name }}</strong>@if ($context->isCaregiver()) · {{ implode(' e ', array_filter([$context->hasHealthAccess() ? 'saúde ('.mb_strtolower($context->caregiver()->access_level->label()).')' : null, $context->hasFinanceAccess() ? 'finanças (só leitura)' : null])) }}@endif.
                    @endif
                </span>
                <span class="flex items-center gap-4">
                    <a href="{{ route('care.index') }}" class="font-medium underline">Pessoas</a>
                    <form method="POST" action="{{ route('care.exit') }}">
                        @csrf
                        <button type="submit" class="font-medium underline">Voltar para minha conta</button>
                    </form>
                </span>
            </div>
        @endif

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
            <div class="mx-auto flex h-14 max-w-6xl 2xl:max-w-[96rem] items-center justify-between gap-3 px-4">
                <div class="flex shrink-0 items-center gap-1">
                @if ($mostraAsideDesktop)
                    {{-- Abre o menu lateral do celular (ver x-menu-celular, no fim da página). Só existe onde a barra lateral do desktop existe. --}}
                    <button type="button" @click="menu = true" :aria-expanded="menu" aria-label="Abrir o menu" data-abrir-menu @class([
                        '-ml-2 flex h-10 w-10 items-center justify-center rounded-xl transition',
                        'text-white hover:bg-white/10' => $dentroDoPerfil,
                        'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10' => ! $dentroDoPerfil,
                    ])>
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>
                @endif
                <a href="{{ route('dashboard') }}" @class([
                    'flex shrink-0 items-center gap-2 font-display text-xl font-semibold tracking-tight',
                    'text-white' => $dentroDoPerfil,
                    'text-brand-800 dark:text-white' => ! $dentroDoPerfil,
                ])>
                    <x-brand-mark class="h-6 w-6" />
                    Cerne
                </a>
                </div>

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

                        {{-- Onde há barra lateral (desktop) e menu lateral (celular), o tema, o Sair e o caminho de volta à área do profissional moram lá:
                             o cabeçalho do celular fica só com o essencial. Sem perfil (nenhum dos dois menus existe), eles continuam aqui. --}}
                        @unless ($mostraAsideDesktop)
                            <x-theme-switcher :current="$theme" />

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" @class(['btn-ghost', '!text-white/60' => $dentroDoPerfil]) title="Sair">
                                    <x-nav-icon name="logout" class="h-4 w-4" />
                                    <span class="ml-1.5 hidden sm:inline">Sair</span>
                                </button>
                            </form>
                        @endunless
                    </div>

                    @if ($podeBuscar)
                        <a href="{{ route('search.index') }}" wire:navigate aria-label="Buscar" title="Buscar" data-busca-link class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white/70 transition hover:bg-white/10 hover:text-white lg:hidden">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        </a>
                    @endif

                    @if ($temTutorial)
                        <x-help-button class="shrink-0 {{ $dentroDoPerfil ? '!text-white/60' : '' }}" />
                    @endif

                    @auth
                        <div @class(['shrink-0', '[&>div>button]:!text-white/60' => $dentroDoPerfil])>
                            <livewire:notifications.notification-center />
                        </div>
                    @endauth
                </div>
            </div>
        </header>

        <main @class([
            'mx-auto w-full flex-1 px-4 py-6 sm:px-6 sm:py-8 lg:px-10 lg:py-10',
            'max-w-6xl 2xl:max-w-[96rem]' => ! $wide,
            'pb-24 lg:pb-10' => $mostraAsideDesktop,  // espaço para o botão "+" (no canto, por cima do fim da página) no celular
            // Cuidador de leitura não vê os botões de escrita (a trava de verdade é no servidor, ver CaregiverWriteGuard); o de edição também não
            // vê o que é só do dono (ex.: trocar o QR de emergência).
            '[&_[data-write]]:hidden' => $context->isCaregiver() && ! $context->canWriteAsCaregiver(),
            '[&_[data-owner-only]]:hidden' => $context->isCaregiver(),
        ])>
            @if (session('status'))
                <div class="mb-6 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">
                    {{ session('status') }}
                </div>
            @endif

            <livewire:tutorial.page-help :route-name="$rotaAtual" />

            {{ $slot }}
        </main>
    </div>
</div>

{{-- ============================================================
     Menu lateral (celular e tablet): desliza da esquerda, no lugar da barra inferior.
     ============================================================ --}}
@if ($mostraAsideDesktop)
    <x-menu-celular
        :secoes="$gavetaSecoes"
        :escuro="(bool) $dentroDoPerfil"
        :cor-barra="$corBarraModulo"
        :cor-ativo="$corAtivoEscuroDesktop"
        :user="$user"
        :perfil="$dentroDoPerfil ? $profile : null"
        :voltar="$areaConsultor ? null : $voltarAoPainel"
        :abas-profissional="$abasProfissional"
        :com-area-profissional="$dentroDoPerfil && $user?->isLinkedProfessional()"
        :consultor-no-perfil="$dentroDoPerfil && $context->isConsultant()"
        :tema="$theme"
    />
@endif

{{-- Atalho de lançamento: quem tem Finanças e está dentro de um perfil. Fora:
     corretor (só Seguros), áreas do consultor e do admin, e as telas que
     funcionam com o acesso travado (assinatura, Minha conta). --}}
@if ($dentroDoPerfil && ! $emPerfilDeCuidado && ! $user?->isBroker() && ! request()->routeIs(['subscription.*', 'my-account', 'care.*']))
    <x-quick-add :na-tela="request()->routeIs('cashflow.index')" />
@endif

{{-- Voltar ao topo (celular): aparece depois de rolar e fica logo acima do "+". Some com o menu aberto. --}}
<button type="button" x-show="topo && ! menu" x-cloak x-transition.opacity @click="window.scrollTo({ top: 0, behavior: 'smooth' })" aria-label="Voltar ao topo" data-voltar-topo
        class="fixed right-4 bottom-[calc(5.25rem+env(safe-area-inset-bottom))] z-[35] flex h-10 w-10 transform-gpu items-center justify-center rounded-full bg-white text-slate-600 shadow-lg ring-1 ring-slate-200 will-change-transform lg:hidden dark:bg-slate-800 dark:text-slate-200 dark:ring-white/10">
    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 14 6-6 6 6"/></svg>
</button>

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
