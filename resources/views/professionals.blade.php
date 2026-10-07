<?php
    // Ver landing.blade.php para o porquê do ?v=.
    $marketingImage = fn (string $nome) => asset("images/marketing/{$nome}.jpg").'?v='.filemtime(public_path("images/marketing/{$nome}.jpg"));

    // Preço do profissional (config/billing.php, 'professional'). Pra
    // calculadora, tudo em centavos inteiros: conta em float erra centavo.
    $prof = config('billing.professional');
    $centavos = fn (string $valor) => (int) bcmul($valor, '100', 0);
    $calculadora = [
        'primeiroTeto' => $prof['first_tier_clients'],
        'primeiroPreco' => $centavos($prof['first_tier_price']),
        'segundoTeto' => $prof['second_tier_clients'],
        'segundoPreco' => $centavos($prof['second_tier_price']),
        'bloco' => $prof['extra_block_clients'],
        'blocoPreco' => $centavos($prof['extra_block_price']),
        'max' => $prof['max_self_service_clients'],
    ];
    $exemplos = collect([30, 50, 100])->filter(fn (int $teto) => \App\Support\ProfessionalPricing::isValidCap($teto));
    $moneyProf = fn (string $valor) => \App\Support\Money::format($valor);

    $telas = [
        ['carteira', 'Painel da carteira', 'carteira-consultor', 'Painel da carteira de um consultor financeiro',
            'Todos os clientes vinculados em uma tela: patrimônio investido, prêmios de seguro por mês, composição da carteira entre individual e casal, e quem ainda não tem seguro de vida.'],
        ['seguros', 'Seguros da carteira', 'carteira-seguros', 'Apólices da carteira agrupadas por tipo, cliente e seguradora',
            'As apólices de todos os clientes, separadas por tipo, cliente e seguradora, com valor segurado, prêmio mensal e data de vencimento à vista.'],
        ['investimentos', 'Investimentos da carteira', 'carteira-investimentos', 'Investimentos de cada cliente da carteira',
            'Os investimentos de cada cliente, com patrimônio, quantidade de ativos e rentabilidade. Filtre por instituição e período de crescimento.'],
        ['datas', 'Datas importantes', 'carteira-datas', 'Vencimentos e aniversários da carteira',
            'Aniversários de clientes, aniversário e vencimento de apólice e vencimento de investimento, por período, com o contato de cada cliente.'],
        ['cliente', 'Cliente aberto', 'carteira-cliente', 'Perfil de um cliente aberto por um consultor, sem a área de Saúde',
            'Abra o perfil de um cliente com um clique. O Cerne avisa que você está no perfil dele e mostra só a sua área: a área de Saúde não aparece.'],
    ];
?>
<x-layouts.marketing title="Cerne para profissionais: acompanhe a carteira dos seus clientes" publico="profissional">

    {{-- Hero --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
        <div class="max-w-2xl md:mx-auto md:max-w-3xl md:text-center">
            <p class="eyebrow text-brand-700 dark:text-brand-300">Cerne para profissionais</p>
            <h1 class="mt-3 font-display text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                Acompanhe a carteira dos seus clientes em um só lugar.
            </h1>
            <p class="mt-5 text-lg text-slate-600 dark:text-slate-300">
                Para consultores financeiros e corretores de seguros. Cada cliente tem a própria conta,
                com regras claras do que cada profissional pode ver.
            </p>
            <div class="mt-8 flex flex-wrap gap-3 md:justify-center">
                <a href="#precos" class="btn-primary px-6 py-3 text-base">Começar 7 dias grátis</a>
                <a href="#painel" class="btn-secondary px-6 py-3 text-base">Ver o painel</a>
            </div>
            <div class="mt-8 flex flex-wrap gap-2 text-xs md:justify-center">
                <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Consultor financeiro</span>
                <span class="badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">Corretor de seguros</span>
                <span class="badge border border-dashed border-slate-300 text-slate-500 dark:border-slate-600 dark:text-slate-400">Em breve: profissionais de saúde</span>
            </div>
        </div>
    </section>

    {{-- Áreas por tipo de profissional --}}
    <section class="border-y border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <p class="eyebrow">Cada um com a sua área</p>
            <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Você vê o que a sua atuação precisa</h2>
            <p class="mt-2 max-w-2xl text-sm text-slate-600 dark:text-slate-300">
                Consultores e corretores nunca veem dados de Saúde. Documentos ficam visíveis só pela
                categoria que o cliente autorizar.
            </p>

            <div class="mt-8 grid gap-5 md:grid-cols-3">
                <div class="card flex flex-col p-6">
                    <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">Consultor financeiro</h3>
                    <ul class="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                        <li>Carteira com todos os clientes vinculados</li>
                        <li>Patrimônio e investimentos consolidados, mês a mês</li>
                        <li>Vida financeira do casal, unida ou separada</li>
                    </ul>
                    <div class="mt-auto pt-5"><a href="{{ route('checkout.professional', ['papel' => 'consultant']) }}" class="btn-secondary w-full">Quero ser consultor parceiro</a></div>
                </div>

                <div class="card flex flex-col p-6">
                    <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">Corretor de seguros</h3>
                    <ul class="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                        <li>Apólices de todos os clientes, por seguradora</li>
                        <li>Vencimento e renovação, sem precisar perguntar</li>
                        <li>Perfil de cada cliente, com histórico</li>
                    </ul>
                    <div class="mt-auto pt-5"><a href="{{ route('checkout.professional', ['papel' => 'broker']) }}" class="btn-secondary w-full">Quero ser corretor parceiro</a></div>
                </div>

                <div class="card flex flex-col gap-2 border-2 border-dashed border-slate-200 bg-transparent p-6 shadow-none dark:border-slate-700">
                    <span class="badge w-fit bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">Em breve</span>
                    <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">Profissionais de saúde</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Personal trainers, nutricionistas e outros profissionais que acompanham o cliente no
                        dia a dia. Estamos preparando uma área própria de saúde, com acesso apenas ao que o
                        cliente autorizar.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- Painel --}}
    <section id="painel" class="mx-auto max-w-6xl scroll-mt-28 px-4 py-16 sm:px-6" x-data="{ aba: 'carteira' }">
        <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Veja como fica o acompanhamento</h2>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">As telas que você vai usar no dia a dia, com dados de demonstração.</p>

        <div class="mt-6 flex flex-wrap gap-2">
            @foreach ($telas as [$chave, $rotulo])
                <button type="button" @click="aba = '{{ $chave }}'" :class="aba === '{{ $chave }}' ? 'bg-brand-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'" class="rounded-full px-4 py-2 text-sm font-medium transition">{{ $rotulo }}</button>
            @endforeach
        </div>

        <div class="mt-6">
            @foreach ($telas as [$chave, $rotulo, $imagem, $alt, $legenda])
                <div x-show="aba === '{{ $chave }}'" @if (! $loop->first) x-cloak @endif>
                    <x-screenshot-frame src="{{ $marketingImage($imagem) }}" alt="{{ $alt }}" />
                    <p class="mt-4 max-w-3xl text-sm text-slate-600 dark:text-slate-300">{{ $legenda }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Como funciona --}}
    <section id="como-funciona" class="scroll-mt-28 border-y border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Como funciona</h2>

            <ol class="mt-8 grid gap-5 md:grid-cols-3">
                @foreach ([
                    ['Você assina', 'Escolha quantos clientes vai acompanhar e teste 7 dias grátis. O preço depende só desse número, não de pacote.'],
                    ['Convida o cliente', 'Envie o convite por e-mail. Quem ainda não tem conta se cadastra pelo link; quem já tem, autoriza o vínculo com um clique.'],
                    ['Acompanha', 'O cliente usa todas as áreas do Cerne sem pagar nada, e você acompanha a sua área na carteira.'],
                ] as $i => [$titulo, $texto])
                    <li class="card p-6">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-800 text-sm font-semibold text-white">{{ $i + 1 }}</span>
                        <h3 class="mt-4 font-display text-lg font-semibold text-slate-900 dark:text-white">{{ $titulo }}</h3>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $texto }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Preços --}}
    <section id="precos" class="mx-auto max-w-6xl scroll-mt-28 px-4 py-16 sm:px-6">
        <p class="eyebrow">Preços</p>
        <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Um valor pela sua carteira, sem escolher pacote</h2>
        <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
            7 dias grátis para testar. O preço depende só de quantos clientes você vincula, igual para consultor e
            para corretor. Cada cliente vinculado usa todas as áreas do Cerne sem pagar nada.
        </p>

        <div class="mt-8 grid gap-5 lg:grid-cols-5">
            <div class="card p-6 lg:col-span-3">
                <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">Quanto custa</h3>

                <dl class="mt-4 divide-y divide-slate-100 text-sm dark:divide-white/10">
                    <div class="flex items-baseline justify-between gap-4 py-3">
                        <dt class="text-slate-600 dark:text-slate-300">Até {{ $prof['first_tier_clients'] }} clientes</dt>
                        <dd class="whitespace-nowrap font-semibold text-slate-900 dark:text-white">{{ $moneyProf($prof['first_tier_price']) }}<span class="text-xs font-normal text-slate-400">/mês</span></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 py-3">
                        <dt class="text-slate-600 dark:text-slate-300">Até {{ $prof['second_tier_clients'] }} clientes</dt>
                        <dd class="whitespace-nowrap font-semibold text-slate-900 dark:text-white">{{ $moneyProf($prof['second_tier_price']) }}<span class="text-xs font-normal text-slate-400">/mês</span></dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 py-3">
                        <dt class="text-slate-600 dark:text-slate-300">Depois disso, a cada {{ $prof['extra_block_clients'] }} clientes a mais</dt>
                        <dd class="whitespace-nowrap font-semibold text-slate-900 dark:text-white">+ {{ $moneyProf($prof['extra_block_price']) }}<span class="text-xs font-normal text-slate-400">/mês</span></dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs font-medium text-slate-500 dark:text-slate-400">Alguns exemplos</p>
                <div class="mt-2 grid grid-cols-3 gap-2">
                    @foreach ($exemplos as $teto)
                        <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $teto }} clientes</p>
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $moneyProf(\App\Support\ProfessionalPricing::priceFor($teto)) }}</p>
                        </div>
                    @endforeach
                </div>

                <ul class="mt-5 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                    <li>Os clientes vinculados não pagam nada e usam finanças, seguros, documentos e saúde.</li>
                    <li>Você vê só a sua área: Saúde nunca, Documentos só o que o cliente autorizar.</li>
                    <li>Chegou no limite? Você aumenta pela sua conta, o acesso é imediato e o novo valor vale na próxima cobrança.</li>
                </ul>
            </div>

            {{-- Calculadora --}}
            <div
                class="card p-6 lg:col-span-2"
                x-data="{
                    cfg: @js($calculadora),
                    n: 25,
                    checkout: @js(route('checkout.professional')),
                    get teto() {
                        const c = this.cfg;
                        const n = Math.max(1, Math.floor(Number(this.n) || 1));
                        if (n <= c.primeiroTeto) return c.primeiroTeto;
                        if (n <= c.segundoTeto) return c.segundoTeto;
                        return c.segundoTeto + Math.ceil((n - c.segundoTeto) / c.bloco) * c.bloco;
                    },
                    get centavos() {
                        const c = this.cfg;
                        if (this.teto <= c.primeiroTeto) return c.primeiroPreco;
                        if (this.teto <= c.segundoTeto) return c.segundoPreco;
                        return c.segundoPreco + ((this.teto - c.segundoTeto) / c.bloco) * c.blocoPreco;
                    },
                    brl(centavos) { return (centavos / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); },
                }"
            >
                <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">Faça a sua conta</h3>
                <label for="calc-clientes" class="mt-4 block text-sm text-slate-600 dark:text-slate-300">
                    Quantos clientes você atende? <strong class="text-slate-900 dark:text-white" x-text="n">25</strong>
                </label>
                <input id="calc-clientes" type="range" min="1" :max="cfg.max" step="1" x-model.number="n" class="mt-3 w-full accent-brand-700">

                <div class="mt-5 rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
                    <p class="text-xs text-slate-500 dark:text-slate-400">Seu limite: até <span x-text="teto">30</span> clientes</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">
                        <span x-text="brl(centavos)">{{ $moneyProf(\App\Support\ProfessionalPricing::priceFor(30)) }}</span><span class="text-sm font-normal text-slate-400">/mês</span>
                    </p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Com o limite cheio, sai a <span x-text="brl(Math.round(centavos / teto))">R$ 7,00</span> por cliente.
                    </p>
                </div>

                <p class="mt-3 text-xs text-slate-400">
                    Mais de {{ $prof['max_self_service_clients'] }} clientes? O valor é combinado caso a caso.
                </p>

                <div class="mt-5 grid gap-2">
                    <a :href="checkout + '?papel=consultant&clientes=' + teto" href="{{ route('checkout.professional', ['papel' => 'consultant', 'clientes' => 30]) }}" class="btn-primary w-full">Quero ser consultor parceiro</a>
                    <a :href="checkout + '?papel=broker&clientes=' + teto" href="{{ route('checkout.professional', ['papel' => 'broker', 'clientes' => 30]) }}" class="btn-secondary w-full">Quero ser corretor parceiro</a>
                </div>
            </div>
        </div>
    </section>

    {{-- Perguntas frequentes --}}
    <section id="perguntas" class="scroll-mt-28 border-t border-slate-200/70 py-16 dark:border-white/10">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Perguntas frequentes</h2>

            <div class="mt-8 space-y-3">
                @foreach ([
                    'Quanto custa?' => 'O valor depende só de quantos clientes você vincula, e é o mesmo para consultor e para corretor: até '.$prof['first_tier_clients'].' clientes, '.$moneyProf($prof['first_tier_price']).' por mês; até '.$prof['second_tier_clients'].', '.$moneyProf($prof['second_tier_price']).'; depois disso, cada '.$prof['extra_block_clients'].' clientes a mais somam '.$moneyProf($prof['extra_block_price']).'. Não existe escolha de pacote.',
                    'Tem período de teste?' => 'Sim, 7 dias grátis. Você só é cobrado depois que o teste termina.',
                    'Meus clientes precisam pagar alguma coisa?' => 'Não. Enquanto o vínculo estiver ativo e a sua assinatura em dia, eles usam todas as áreas do Cerne sem custo nenhum.',
                    'O que acontece quando eu chego no limite de clientes?' => 'O Cerne não deixa vincular o próximo cliente até você aumentar o limite, pela página de assinatura. O acesso aos novos clientes é imediato e o novo valor passa a valer a partir da próxima cobrança.',
                    'O que eu consigo ver dos meus clientes?' => 'Consultores e corretores acompanham a parte financeira e de seguros dos clientes vinculados e ativos. Dados de Saúde, nunca. Documentos, só o que o cliente autorizar.',
                    'Como funciona a cobrança?' => 'Mensal, processada pela Asaas, só em cartão de crédito ou Pix. Você escolhe a forma de pagamento perto do fim do teste, e avisamos 3 dias antes por e-mail e dentro do Cerne. Depois do último dia de teste, o acesso fica bloqueado até o pagamento ser confirmado. Nos meses seguintes você recebe o link de pagamento; quem escolhe Pix recebe um aviso por e-mail 3 dias antes de cada vencimento.',
                    'Posso cancelar quando quiser?' => 'Sim, pela sua área de conta, sem precisar falar com ninguém. O acesso é encerrado na hora do cancelamento, sem desconto proporcional do período já pago.',
                    'Profissionais de saúde podem usar?' => 'Ainda não. Hoje o Cerne está aberto para consultores financeiros e corretores de seguros. Estamos preparando uma área para profissionais de saúde, como personal trainers e nutricionistas.',
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
        <h2 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Pronto para organizar a sua carteira?</h2>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('checkout.professional', ['papel' => 'consultant']) }}" class="btn-primary px-6 py-3 text-base">Quero ser consultor parceiro</a>
            <a href="{{ route('checkout.professional', ['papel' => 'broker']) }}" class="btn-secondary px-6 py-3 text-base">Quero ser corretor parceiro</a>
        </div>
        <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">
            Quer usar o Cerne para a sua própria vida?
            <a href="{{ route('home') }}" class="font-medium text-brand-700 underline dark:text-brand-300">Veja a página para você</a>
        </p>
    </section>

</x-layouts.marketing>
