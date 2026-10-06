<?php
    // Cache-busting pras capturas de tela: o header delas é
    // "Cache-Control: public, max-age=604800" (ver public/.htaccess) — sem
    // isso, atualizar a imagem no servidor não adianta nada pra quem já
    // carregou a página antes, o navegador segue servindo a versão velha
    // por até 7 dias do próprio cache, sem bater no servidor de novo.
    $marketingImage = fn (string $nome) => asset("images/marketing/{$nome}.jpg").'?v='.filemtime(public_path("images/marketing/{$nome}.jpg"));

    // Pacotes do usuário final do mais barato ao mais completo.
    $pacotesPorPreco = collect(\App\Enums\SubscriptionBundle::cases())
        ->sortBy(fn ($pacote) => config('billing.prices.'.$pacote->value))
        ->values();

    $abasVitrine = [
        ['painel', 'Visão geral', 'bg-brand-800'],
        ['fluxo', 'Fluxo de caixa', 'bg-brand-800'],
        ['investimentos', 'Investimentos', 'bg-brand-800'],
        ['seguros', 'Seguros', 'bg-seguros-800'],
        ['documentos', 'Documentos', 'bg-documentos-800'],
        ['saude', 'Saúde', 'bg-saude-800'],
    ];
?>
<x-layouts.marketing title="Cerne: finanças, seguros, documentos e saúde em um só lugar">

    {{-- Hero --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
        <div class="max-w-2xl md:mx-auto md:max-w-3xl md:text-center">
            <p class="eyebrow text-brand-700 dark:text-brand-300">Cerne para você</p>
            <h1 class="mt-3 font-display text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                Finanças, seguros, documentos, saúde: tudo o que sustenta sua vida, em um só lugar.
            </h1>
            <p class="mt-5 text-lg text-slate-600 dark:text-slate-300">
                Sozinho, ou com quem te ajuda a cuidar dela, com a privacidade que você escolher.
            </p>
            <div class="mt-8 flex flex-wrap gap-3 md:justify-center">
                <a href="#planos" class="btn-primary px-6 py-3 text-base">Começar 7 dias grátis</a>
                <a href="#por-dentro" class="btn-secondary px-6 py-3 text-base">Ver por dentro</a>
            </div>
            <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">
                É consultor, corretor ou outro profissional?
                <a href="{{ route('professionals') }}" class="font-medium text-brand-700 underline dark:text-brand-300">Conheça o Cerne para profissionais</a>
            </p>
        </div>
    </section>

    {{-- Vitrine de telas --}}
    <section id="por-dentro" class="mx-auto max-w-6xl scroll-mt-28 px-4 py-16 sm:px-6" x-data="{ aba: 'painel' }">
        <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Veja o Cerne por dentro</h2>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">As mesmas telas que você vai usar, com dado de verdade.</p>

        <div class="mt-6 flex flex-wrap gap-2">
            @foreach ($abasVitrine as [$chave, $rotulo, $corAtiva])
                <button type="button" @click="aba = '{{ $chave }}'" :class="aba === '{{ $chave }}' ? '{{ $corAtiva }} text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'" class="rounded-full px-4 py-2 text-sm font-medium transition">{{ $rotulo }}</button>
            @endforeach
        </div>

        <div class="mt-6">
            <x-screenshot-frame x-show="aba === 'painel'" src="{{ $marketingImage('painel') }}" alt="Visão geral do painel financeiro no Cerne" />
            <x-screenshot-frame x-show="aba === 'fluxo'" x-cloak src="{{ $marketingImage('fluxo-de-caixa') }}" alt="Fluxo de caixa do mês no Cerne" />
            <x-screenshot-frame x-show="aba === 'investimentos'" x-cloak src="{{ $marketingImage('investimentos') }}" alt="Carteira de investimentos com perfil do investidor e alocação por classe de ativo" />
            <x-screenshot-frame x-show="aba === 'seguros'" x-cloak src="{{ $marketingImage('seguros') }}" alt="Apólices de seguro organizadas no Cerne" />
            <x-screenshot-frame x-show="aba === 'documentos'" x-cloak src="{{ $marketingImage('documentos') }}" alt="Cofre de documentos no Cerne" />
            <x-screenshot-frame x-show="aba === 'saude'" x-cloak src="{{ $marketingImage('saude') }}" alt="Treino de academia acompanhado no Cerne" />
        </div>
    </section>

    {{-- Pra quem --}}
    <section class="border-y border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto grid max-w-6xl gap-5 px-4 sm:px-6 md:grid-cols-2">
            <div class="card p-6">
                <p class="eyebrow">Para você</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Assine direto</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Você cria sua conta, escolhe o plano e já começa a acompanhar sua vida inteira,
                    com a privacidade de escolher o que fica só seu e o que é do casal.
                </p>
                <a href="#planos" class="mt-4 inline-block text-sm font-medium text-brand-700 underline dark:text-brand-300">Ver os planos</a>
            </div>

            <div class="card p-6">
                <p class="eyebrow">Com acompanhamento</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Seu consultor ou corretor já usa o Cerne?</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Se a assinatura dele está em dia, o seu acesso vem por ele, sem custo para você.
                    Ele vê só a área dele: Saúde nunca, e Documentos apenas o que você autorizar.
                </p>
                <a href="{{ route('professionals') }}" class="mt-4 inline-block text-sm font-medium text-brand-700 underline dark:text-brand-300">Indicar o Cerne ao seu profissional</a>
            </div>
        </div>
    </section>

    {{-- Módulos --}}
    <section id="modulos" class="mx-auto max-w-6xl scroll-mt-28 px-4 py-16 sm:px-6">
        <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">O que o Cerne organiza</h2>
        <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card overflow-hidden">
                <div class="h-1.5 bg-brand-800"></div>
                <div class="p-5">
                    <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">Finanças</h3>
                    <ul class="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                        <li>Fluxo de caixa e contas fixas</li>
                        <li>Investimentos e objetivos</li>
                        <li>Importação de extrato com IA</li>
                    </ul>
                </div>
            </div>
            <div class="card overflow-hidden">
                <div class="h-1.5 bg-seguros-800"></div>
                <div class="p-5">
                    <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">Seguros</h3>
                    <ul class="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                        <li>Apólices em um só lugar</li>
                        <li>Datas importantes e renovação</li>
                        <li>Acompanhamento com seu corretor</li>
                    </ul>
                </div>
            </div>
            <div class="card overflow-hidden">
                <div class="h-1.5 bg-documentos-800"></div>
                <div class="p-5">
                    <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">Documentos</h3>
                    <ul class="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                        <li>CNH, passaporte, apólices e exames</li>
                        <li>Cofre digital com controle de acesso</li>
                        <li>Visibilidade por categoria</li>
                    </ul>
                </div>
            </div>
            <div class="card overflow-hidden">
                <div class="h-1.5 bg-saude-800"></div>
                <div class="p-5">
                    <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">Saúde</h3>
                    <ul class="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                        <li>Treino de academia com histórico</li>
                        <li>Relatório de evolução em PDF</li>
                        <li>Ficha de saúde com QR de emergência</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Planos --}}
    <section id="planos" class="scroll-mt-28 border-t border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="text-center">
                <p class="eyebrow">Planos</p>
                <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Escolha o seu plano</h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">7 dias grátis em qualquer plano. Depois, cobrança mensal, sem fidelidade.</p>
            </div>

            <div class="mt-12 grid items-stretch gap-6 lg:grid-cols-3">
                @foreach ($pacotesPorPreco as $pacote)
                    @php
                        $destaque = $pacote === \App\Enums\SubscriptionBundle::Completo;
                        [$reais, $centavos] = explode(',', \App\Support\Money::format(config('billing.prices.'.$pacote->value), false));
                    @endphp
                    <div @class([
                        'relative flex flex-col rounded-2xl bg-white p-7 shadow-card dark:bg-slate-800',
                        'ring-1 ring-brand-950/10 dark:ring-white/10' => ! $destaque,
                        'ring-2 ring-accent-500 shadow-[0_0_48px_-12px_rgb(16_185_129_/_0.45)] lg:scale-[1.03]' => $destaque,
                    ])>
                        @if ($destaque)
                            <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-accent-600 px-4 py-1 text-[11px] font-bold tracking-wider text-white uppercase shadow-lg">Mais completo</span>
                        @endif

                        <h3 class="font-display text-xl font-semibold text-slate-900 dark:text-white">{{ $pacote->label() }}</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ collect($pacote->modules())->map(fn ($m) => $m->label())->join(' · ') }}
                        </p>

                        <p class="mt-6 flex items-baseline gap-1 text-slate-900 dark:text-white">
                            <span class="text-xl font-semibold text-slate-500 dark:text-slate-400">R$</span>
                            <span class="font-display text-6xl font-semibold tracking-tight">{{ $reais }}</span>
                            <span class="text-2xl font-semibold">,{{ $centavos }}</span>
                            <span class="text-sm font-normal text-slate-400">/mês</span>
                        </p>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Cartão de crédito ou Pix · cancele quando quiser</p>
                        <p class="mt-3 w-fit rounded-full bg-accent-500/10 px-3 py-1 text-xs font-semibold text-accent-700 dark:text-accent-400">7 dias grátis, sem cobrança hoje</p>

                        <ul class="mt-6 space-y-3 border-t border-slate-200/70 pt-6 text-sm text-slate-600 dark:border-white/10 dark:text-slate-300">
                            @foreach ($pacote->modules() as $modulo)
                                <li class="flex gap-2.5">
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-accent-600 dark:text-accent-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                                    <span><strong class="text-slate-900 dark:text-white">{{ $modulo->label() }}:</strong> {{ $modulo->summary() }}</span>
                                </li>
                            @endforeach
                            @foreach (['Perfil individual ou de casal, na mesma assinatura', 'Sem fidelidade: cancele quando quiser'] as $item)
                                <li class="flex gap-2.5">
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-accent-600 dark:text-accent-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-auto pt-8">
                            <a href="{{ route('checkout.show', $pacote->value) }}" class="btn-primary w-full py-3 text-base">Começar 7 dias grátis</a>
                            <p class="mt-2 text-center text-xs text-slate-400">A primeira cobrança só acontece depois do teste.</p>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-10 text-center text-sm text-slate-500 dark:text-slate-400">
                Já tem um consultor ou corretor parceiro? Se a assinatura dele está em dia, seu acesso
                vem por ele, sem custo para você. Vale perguntar antes de assinar.
            </p>
        </div>
    </section>

    {{-- Perguntas frequentes --}}
    <section id="perguntas" class="scroll-mt-28 border-t border-slate-200/70 py-16 dark:border-white/10">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Perguntas frequentes</h2>

            <div class="mt-8 space-y-3">
                @foreach ([
                    'Tem período de teste?' => 'Sim, 7 dias grátis em qualquer plano, para usar e decidir com calma. Você só é cobrado depois que o teste termina.',
                    'Como funciona a cobrança?' => 'Mensal, processada pela Asaas, só em cartão de crédito ou Pix. Nenhum dos dois cobra sozinho hoje: você recebe o link de pagamento quando o teste acaba (ou a cada mês seguinte) e paga com um clique. Quem escolhe Pix recebe um aviso por e-mail 3 dias antes de cada vencimento.',
                    'Posso cancelar quando quiser?' => 'Sim, pela sua área de conta, sem precisar falar com ninguém. O acesso é encerrado na hora do cancelamento, sem desconto proporcional do período já pago.',
                    'O consultor ou corretor vê meus dados de Saúde?' => 'Não, nunca, em nenhuma circunstância, mesmo vinculado e com acesso ativo ao resto do seu perfil.',
                    'Minha assinatura cobre meu cônjuge também?' => 'Sim. A assinatura é por perfil financeiro, não por pessoa: se vocês dividem o mesmo perfil de casal, uma assinatura libera o acesso para os dois.',
                    'Qual a diferença entre assinar direto e ter um profissional vinculado?' => 'Se o seu consultor ou corretor já tem assinatura profissional ativa, seu acesso vem sem custo por esse vínculo. Assinar direto só é necessário se você quiser usar o Cerne sozinho, sem nenhum profissional.',
                    'Meus dados são compartilhados com terceiros?' => 'Não, além do necessário para processar a cobrança (Asaas). Você decide o que fica privado entre você e seu cônjuge, e o que o seu consultor ou corretor vê.',
                    'Sou profissional. Posso usar o Cerne com os meus clientes?' => 'Sim. Existe uma página própria para profissionais, com os preços por número de clientes e as telas de acompanhamento.',
                ] as $pergunta => $resposta)
                    <div class="card overflow-hidden" x-data="{ open: false }">
                        <button type="button" @click="open = ! open" class="flex w-full items-center justify-between gap-4 p-5 text-left">
                            <span class="font-medium text-slate-900 dark:text-white">{{ $pergunta }}</span>
                            <span class="shrink-0 text-xl leading-none text-slate-400" x-text="open ? '−' : '+'"></span>
                        </button>
                        <p class="px-5 pb-5 text-sm text-slate-600 dark:text-slate-300" x-show="open" x-cloak>{{ $resposta }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA final --}}
    <section class="mx-auto max-w-6xl px-4 py-16 text-center sm:px-6">
        <h2 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Pronto para começar?</h2>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="#planos" class="btn-primary px-6 py-3 text-base">Começar 7 dias grátis</a>
            <a href="{{ route('login') }}" class="btn-secondary px-6 py-3 text-base">Já tenho conta</a>
        </div>
        <div class="mt-6 flex flex-wrap justify-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span class="badge bg-slate-100 dark:bg-slate-800">7 dias grátis</span>
            <span class="badge bg-slate-100 dark:bg-slate-800">Cancele quando quiser</span>
            <span class="badge bg-slate-100 dark:bg-slate-800">Sem taxa de adesão</span>
            <span class="badge bg-slate-100 dark:bg-slate-800">Cartão de crédito ou Pix</span>
        </div>
        <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">
            É profissional?
            <a href="{{ route('professionals') }}" class="font-medium text-brand-700 underline dark:text-brand-300">Veja o Cerne para profissionais</a>
        </p>
    </section>

</x-layouts.marketing>
