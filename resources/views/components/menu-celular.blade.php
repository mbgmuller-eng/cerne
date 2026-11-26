@props([
    // [['label', 'tint', 'cadastrosDe' (índice ou null), 'items' => [[rota, rótulo, ícone, selo|null, 'erro'|'pendente'|null]]]]
    'secoes',
    // Dentro do perfil o menu leva a cor do módulo (como a barra lateral do desktop); nas áreas do profissional e do admin é claro/escuro.
    'escuro' => false,
    'corBarra' => '',
    'corAtivo' => '',
    'user',
    'perfil' => null,
    'voltar' => null,
    // Grupos do profissional (Consultoria, Seguros, Gestão, Plataforma), mostrados recolhidos dentro do perfil de um cliente.
    'abasProfissional' => [],
    'comAreaProfissional' => false,
    'consultorNoPerfil' => false,
    'tema',
])

{{--
    Menu lateral do CELULAR: desliza da esquerda e substitui a barra inferior (que tinha uma gaveta por categoria). São as MESMAS telas da barra
    lateral do desktop, em grupos, então item novo no menu aparece nos dois. Abre pelo botão do cabeçalho; fecha tocando fora, no X, na tecla
    Esc, ao arrastar para a esquerda ou ao escolher uma tela. O estado (`menu`) vem do x-data do <body>.

    O fundo escuro fica FORA da gaveta de propósito: assim ele cobre a tela inteira e um toque nele fecha o menu.
--}}
<div
    x-show="menu"
    x-transition.opacity
    x-cloak
    @click="menu = false"
    class="fixed inset-0 z-40 bg-brand-950/50 backdrop-blur-sm lg:hidden"
    aria-hidden="true"
></div>

<aside data-menu-celular
    x-show="menu"
    x-cloak
    x-data="{ inicioX: 0 }"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="-translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="-translate-x-full"
    @keydown.escape.window="menu = false"
    @touchstart.passive="inicioX = $event.touches[0].clientX"
    @touchend.passive="if (inicioX - $event.changedTouches[0].clientX > 70) menu = false"
    role="dialog"
    aria-modal="true"
    aria-label="Menu"
    @class([
        'fixed inset-y-0 left-0 z-50 flex w-[85vw] max-w-sm flex-col shadow-2xl lg:hidden dark:bg-slate-900',
        $corBarra.' dark:bg-slate-900' => $escuro,
        'bg-white' => ! $escuro,
    ])
    style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom)"
>
    {{-- Quem é: avatar, nome, e-mail e, dentro de um perfil, o nome dele. --}}
    <div @class(['flex items-center gap-3 border-b px-4 py-4', 'border-white/10' => $escuro, 'border-brand-950/5 dark:border-white/10' => ! $escuro])>
        <div @class(['flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-base font-semibold', 'bg-white/10 text-white' => $escuro, 'bg-brand-100 text-brand-800 dark:bg-white/10 dark:text-white' => ! $escuro])>
            {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
        </div>
        <div class="min-w-0 flex-1">
            <p @class(['truncate text-sm font-medium', 'text-white' => $escuro, 'text-slate-800 dark:text-slate-100' => ! $escuro])>{{ $user->name }}</p>
            <p @class(['truncate text-xs', 'text-white/50' => $escuro, 'text-slate-500 dark:text-slate-400' => ! $escuro])>{{ $perfil?->profile_name ?? $user->email }}</p>
        </div>
        <button type="button" @click="menu = false" aria-label="Fechar o menu" @class(['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition', 'text-white/60 hover:bg-white/10 hover:text-white' => $escuro, 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-white/10' => ! $escuro])>
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>

    <nav class="flex-1 space-y-0.5 overflow-y-auto overscroll-contain px-3 py-3" data-menu-celular-lista>
        {{-- O caminho de volta à área do profissional (consultor ou corretor dentro de um cliente, ou administrador). --}}
        @if ($voltar)
            <a href="{{ route($voltar['rota']) }}" @class(['mb-3 flex items-center gap-3 rounded-xl px-3 py-2.5 transition', 'bg-white/10 text-white hover:bg-white/15' => $escuro, 'border border-brand-950/10 bg-slate-50 text-brand-800 hover:bg-slate-100 dark:border-white/10 dark:bg-white/5 dark:text-white' => ! $escuro])>
                <x-nav-icon name="back" />
                <span class="min-w-0 leading-tight">
                    <span @class(['block text-[10px] font-semibold tracking-wide uppercase', 'text-white/50' => $escuro, 'text-slate-400 dark:text-slate-500' => ! $escuro])>Voltar para</span>
                    <span class="block truncate text-sm font-medium">{{ $voltar['nome'] }}</span>
                </span>
            </a>
        @endif

        @php
            // Os grupos recolhem para o menu não virar uma lista longa: abre o que tem a tela de agora (ou o primeiro, se nenhum tiver).
            $indiceAtivo = null;
            foreach ($secoes as $i => $sec) {
                foreach ($sec['items'] as $item) {
                    if (request()->routeIs(...\App\Support\MenuRoutes::activePatterns($item[0]))) {
                        $indiceAtivo = $i;
                        break 2;
                    }
                }
            }
            $indiceAtivo ??= array_key_first($secoes);
        @endphp

        @foreach ($secoes as $secao)
            <div @class(['pt-2' => ! $loop->first]) x-data="{ aberto: {{ $loop->index === $indiceAtivo ? 'true' : 'false' }} }" data-menu-grupo>
                <button type="button" @click="aberto = ! aberto" :aria-expanded="aberto" data-menu-grupo-titulo @class(['flex w-full items-center justify-between rounded-lg px-3 py-2 text-[10px] font-semibold tracking-wide uppercase transition', $secao['tint']])>
                    <span>{{ $secao['label'] }}</span>
                    <svg class="h-3.5 w-3.5 transition" :class="aberto ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                </button>
                <div x-show="aberto" x-cloak class="space-y-0.5">
                @foreach ($secao['items'] as [$rota, $rotulo, $icone, $selo, $tipoSelo])
                    @if (($secao['cadastrosDe'] ?? null) !== null && $loop->index === $secao['cadastrosDe'])
                        <p @class(['px-3 pt-4 pb-1 text-[10px] font-semibold tracking-wide uppercase', 'text-white/40' => $escuro, 'text-slate-400 dark:text-slate-500' => ! $escuro])>Cadastros e regras</p>
                    @endif
                    <a
                        href="{{ route($rota) }}"
                        @class([
                            $escuro ? 'nav-item-chrome' : 'nav-item',
                            ($escuro ? 'nav-item-chrome-active '.$corAtivo : 'nav-item-active') => request()->routeIs(...\App\Support\MenuRoutes::activePatterns($rota)),
                        ])
                    >
                        <x-nav-icon :name="$icone" />
                        <span>{{ $rotulo }}</span>
                        @if ($selo)
                            <span @class(['badge ml-auto ring-1', 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20' => $tipoSelo === 'erro', 'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => $tipoSelo !== 'erro'])>{{ $selo }}</span>
                        @endif
                    </a>
                @endforeach
                </div>
            </div>
        @endforeach

        {{-- Atalhos do profissional, recolhidos: dentro de um cliente o menu do cliente é o que se usa (mesmo desenho da barra lateral do desktop). --}}
        @if ($comAreaProfissional)
            <div class="pt-4" x-data="{ aberto: false }" data-menu-area-profissional>
                <button type="button" @click="aberto = ! aberto" :aria-expanded="aberto" @class(['flex w-full items-center justify-between px-3 pb-1 text-[10px] font-semibold tracking-wide uppercase transition', 'text-white/50 hover:text-white/80' => $escuro, 'text-slate-400 hover:text-slate-600 dark:text-slate-500' => ! $escuro])>
                    <span>Área do profissional</span>
                    <svg class="h-3 w-3 transition" :class="aberto ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                </button>
                <div x-show="aberto" x-cloak class="space-y-0.5">
                    @foreach ($abasProfissional as $grupo)
                        <p @class(['px-3 pt-2 pb-0.5 text-[10px] font-semibold tracking-wide uppercase', 'text-white/30' => $escuro, 'text-slate-300 dark:text-slate-600' => ! $escuro])>{{ $grupo['label'] }}</p>
                        @foreach ($grupo['items'] as [$rota, $rotulo, $curto, $icone])
                            <a href="{{ route($rota) }}" @class([$escuro ? 'nav-item-chrome' : 'nav-item'])>
                                <x-nav-icon :name="$icone" />
                                <span>{{ $rotulo }}</span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endif
    </nav>

    {{-- Rodapé: a conta, o tema e o Sair (que antes ficavam espalhados entre o cabeçalho e a barra inferior). --}}
    <div @class(['border-t p-3', 'border-white/10' => $escuro, 'border-brand-950/5 dark:border-white/10' => ! $escuro])>
        @if ($consultorNoPerfil)
            <div class="mb-2 rounded-xl bg-amber-500/10 px-3 py-2 text-xs text-amber-300 ring-1 ring-amber-500/20">
                Você está vendo este perfil <span class="font-medium">como {{ $user->isBroker() ? 'corretor' : 'consultor' }}</span>.
            </div>
        @endif

        <a href="{{ route('home') }}" class="{{ $escuro ? 'nav-item-chrome' : 'nav-item' }}" data-site-do-cerne>
            <x-nav-icon name="site" />
            <span>Site do Cerne</span>
        </a>

        <a href="{{ route('my-account') }}" @class([$escuro ? 'nav-item-chrome' : 'nav-item', ($escuro ? 'nav-item-chrome-active '.$corAtivo : 'nav-item-active') => request()->routeIs('my-account')])>
            <x-nav-icon name="users" />
            <span>Minha conta</span>
        </a>

        <div class="mt-2 flex items-center justify-between gap-2 px-1">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" @class(['btn-ghost px-2', '!text-white/60' => $escuro]) title="Sair">
                    <x-nav-icon name="logout" class="h-4 w-4" />
                    <span class="ml-1.5 text-xs">Sair</span>
                </button>
            </form>
            <x-theme-switcher :current="$tema" />
        </div>
    </div>
</aside>
