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
    $exemplosProfissional = collect([30, 50, 100])->filter(fn (int $teto) => \App\Support\ProfessionalPricing::isValidCap($teto));
    $moneyProf = fn (string $valor) => \App\Support\Money::format($valor);
?>
<x-layouts.marketing title="Cerne: finanças, seguros, documentos e saúde em um só lugar">

    {{-- Hero --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
        <div class="max-w-2xl md:mx-auto md:max-w-3xl md:text-center">
            <p class="eyebrow text-brand-700 dark:text-brand-300">Cerne</p>
            <h1 class="mt-3 font-display text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                Finanças, seguros, documentos, saúde: tudo o que sustenta sua vida, em um só lugar.
            </h1>
            <p class="mt-5 text-lg text-slate-600 dark:text-slate-300">
                Sozinho, ou com quem te ajuda a cuidar dela, com a privacidade que você escolher.
            </p>
            <div class="mt-8 flex flex-wrap gap-3 md:justify-center">
                <a href="#planos" class="btn-primary px-6 py-3 text-base">Assinar</a>
                <a href="#planos-profissionais" class="btn-secondary px-6 py-3 text-base">Sou consultor ou corretor</a>
            </div>
        </div>
    </section>

    {{-- Vitrine de telas --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6" x-data="{ aba: 'painel' }">
        <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Veja o Cerne por dentro</h2>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">As mesmas telas que você vai usar, com dado de verdade.</p>

        <div class="mt-6 flex flex-wrap gap-2">
            <button type="button" @click="aba = 'painel'" :class="aba === 'painel' ? 'bg-brand-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'" class="rounded-full px-4 py-2 text-sm font-medium transition">Visão geral</button>
            <button type="button" @click="aba = 'fluxo'" :class="aba === 'fluxo' ? 'bg-brand-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'" class="rounded-full px-4 py-2 text-sm font-medium transition">Fluxo de caixa</button>
            <button type="button" @click="aba = 'seguros'" :class="aba === 'seguros' ? 'bg-seguros-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'" class="rounded-full px-4 py-2 text-sm font-medium transition">Seguros</button>
            <button type="button" @click="aba = 'documentos'" :class="aba === 'documentos' ? 'bg-documentos-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'" class="rounded-full px-4 py-2 text-sm font-medium transition">Documentos</button>
            <button type="button" @click="aba = 'saude'" :class="aba === 'saude' ? 'bg-saude-800 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'" class="rounded-full px-4 py-2 text-sm font-medium transition">Saúde</button>
        </div>

        <div class="mt-6">
            <x-screenshot-frame x-show="aba === 'painel'" src="{{ $marketingImage('painel') }}" alt="Visão geral do painel financeiro no Cerne" />
            <x-screenshot-frame x-show="aba === 'fluxo'" x-cloak src="{{ $marketingImage('fluxo-de-caixa') }}" alt="Fluxo de caixa do mês no Cerne" />
            <x-screenshot-frame x-show="aba === 'seguros'" x-cloak src="{{ $marketingImage('seguros') }}" alt="Apólices de seguro organizadas no Cerne" />
            <x-screenshot-frame x-show="aba === 'documentos'" x-cloak src="{{ $marketingImage('documentos') }}" alt="Cofre de documentos no Cerne" />
            <x-screenshot-frame x-show="aba === 'saude'" x-cloak src="{{ $marketingImage('saude') }}" alt="Treino de academia acompanhado no Cerne" />
        </div>
    </section>

    {{-- Pra quem --}}
    <section class="border-y border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="card p-6">
                <p class="eyebrow">Para você</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Assine direto</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Você cria sua conta, escolhe o pacote e já começa a acompanhar sua vida inteira,
                    com a privacidade de escolher o que fica só seu e o que é do casal.
                    <a href="#planos" class="font-medium text-brand-700 underline dark:text-brand-300">Ver os preços</a>
                </p>
                <ul class="mt-4 grid gap-2 text-sm text-slate-600 sm:grid-cols-2 dark:text-slate-300">
                    <li><strong class="text-slate-900 dark:text-white">Finanças:</strong> fluxo de caixa, contas fixas e investimentos</li>
                    <li><strong class="text-slate-900 dark:text-white">Seguros:</strong> apólices e datas de renovação</li>
                    <li><strong class="text-slate-900 dark:text-white">Documentos:</strong> CNH, passaporte e exames guardados</li>
                    <li><strong class="text-slate-900 dark:text-white">Saúde:</strong> treino de academia e ficha de emergência</li>
                </ul>
            </div>

            <div id="profissionais" class="mt-10">
                <p class="eyebrow">Para profissionais</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Cada um vê só a área dele</h2>
                <p class="mt-2 max-w-2xl text-sm text-slate-600 dark:text-slate-300">
                    Os clientes que você vincula usam todas as áreas do Cerne sem pagar nada: quem
                    paga é a sua assinatura, pelo número de clientes. Nenhum profissional enxerga
                    dado de Saúde, em nenhuma circunstância. Documentos fica visível só pela
                    categoria que o cliente autorizar.
                    <a href="#planos-profissionais" class="font-medium text-brand-700 underline dark:text-brand-300">Ver os preços</a>
                </p>

                <div class="mt-6 grid gap-5 md:grid-cols-3">
                    <div class="card flex flex-col overflow-hidden">
                        <img src="{{ $marketingImage('carteira-consultor') }}" alt="Painel da carteira de um consultor financeiro" class="h-40 w-full object-cover object-top">
                        <div class="flex flex-1 flex-col p-5">
                            <h3 class="font-display text-base font-semibold text-slate-900 dark:text-white">Consultor financeiro</h3>
                            <ul class="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                                <li>Carteira com todos os clientes vinculados</li>
                                <li>Patrimônio consolidado, mês a mês</li>
                                <li>Vida financeira do casal, unida ou separada</li>
                            </ul>
                            <a href="{{ route('register', ['papel' => 'consultant']) }}" class="btn-secondary mt-4 w-full">Quero ser consultor parceiro</a>
                        </div>
                    </div>

                    <div class="card flex flex-col overflow-hidden">
                        <img src="{{ $marketingImage('carteira-seguros') }}" alt="Apólices da carteira de um corretor de seguros" class="h-40 w-full object-cover object-top">
                        <div class="flex flex-1 flex-col p-5">
                            <h3 class="font-display text-base font-semibold text-slate-900 dark:text-white">Corretor de seguros</h3>
                            <ul class="mt-3 space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                                <li>Apólices de todos os clientes, por seguradora</li>
                                <li>Vencimento e renovação, sem precisar perguntar</li>
                                <li>Perfil de cada cliente, com histórico</li>
                            </ul>
                            <a href="{{ route('register', ['papel' => 'broker']) }}" class="btn-secondary mt-4 w-full">Quero ser corretor parceiro</a>
                        </div>
                    </div>

                    <div class="card flex flex-col gap-2 border-2 border-dashed border-slate-200 bg-transparent p-5 shadow-none dark:border-slate-700">
                        <span class="badge w-fit bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">Em breve</span>
                        <h3 class="font-display text-base font-semibold text-slate-900 dark:text-white">Profissional de saúde</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Acompanhamento de paciente dentro do Cerne, com o mesmo bloqueio de
                            privacidade que já protege o resto da plataforma.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Módulos --}}
    <section id="modulos" class="mx-auto max-w-6xl px-4 py-16 sm:px-6">
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

    {{-- Planos: usuário final --}}
    <section id="planos" class="border-t border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <p class="eyebrow">Para você</p>
            <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Planos para quem assina direto</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">7 dias grátis para testar. Depois, um valor mensal, cancele quando quiser.</p>

            <div class="mt-8 grid gap-5 sm:grid-cols-3">
                @foreach ($pacotesPorPreco as $pacote)
                    @php $destaque = $pacote === \App\Enums\SubscriptionBundle::Completo; @endphp
                    <div @class([
                        'card flex flex-col gap-3 p-6',
                        'ring-2 ring-brand-700 dark:ring-brand-400' => $destaque,
                    ])>
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">{{ $pacote->label() }}</h3>
                            @if ($destaque)
                                <span class="badge shrink-0 bg-brand-50 text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">Tudo incluso</span>
                            @endif
                        </div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ collect($pacote->modules())->map(fn ($m) => $m->label())->join(' · ') }}
                        </p>
                        <p class="mt-auto text-2xl font-semibold text-slate-900 dark:text-white">
                            {{ \App\Support\Money::format(config('billing.prices.'.$pacote->value)) }}
                            <span class="text-sm font-normal text-slate-400">/mês</span>
                        </p>
                        <a href="{{ route('register') }}" class="btn-primary w-full">Assinar</a>
                    </div>
                @endforeach
            </div>

            <p class="mt-5 text-sm text-slate-500 dark:text-slate-400">
                Já tem um consultor ou corretor parceiro? Se a assinatura dele está em dia, seu acesso
                vem por ele, sem custo para você. Vale perguntar antes de assinar.
            </p>
        </div>
    </section>

    {{-- Planos: consultor e corretor --}}
    <section id="planos-profissionais" class="border-t border-slate-200/70 py-16 dark:border-white/10">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <p class="eyebrow">Para consultores e corretores</p>
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
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $moneyProf($prof['first_tier_price']) }}<span class="text-xs font-normal text-slate-400">/mês</span></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-3">
                            <dt class="text-slate-600 dark:text-slate-300">Até {{ $prof['second_tier_clients'] }} clientes</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $moneyProf($prof['second_tier_price']) }}<span class="text-xs font-normal text-slate-400">/mês</span></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-3">
                            <dt class="text-slate-600 dark:text-slate-300">Depois disso, a cada {{ $prof['extra_block_clients'] }} clientes a mais</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">+ {{ $moneyProf($prof['extra_block_price']) }}<span class="text-xs font-normal text-slate-400">/mês</span></dd>
                        </div>
                    </dl>

                    <p class="mt-4 text-xs font-medium text-slate-500 dark:text-slate-400">Alguns exemplos</p>
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        @foreach ($exemplosProfissional as $teto)
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
                        <a href="{{ route('register', ['papel' => 'consultant']) }}" class="btn-primary w-full">Quero ser consultor parceiro</a>
                        <a href="{{ route('register', ['papel' => 'broker']) }}" class="btn-secondary w-full">Quero ser corretor parceiro</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Perguntas frequentes --}}
    <section class="border-t border-slate-200/70 py-16 dark:border-white/10">
        <div class="mx-auto max-w-3xl px-4 sm:px-6">
            <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Perguntas frequentes</h2>

            <div class="mt-8 space-y-3">
                @foreach ([
                    'Tem período de teste?' => 'Sim, 7 dias grátis, tanto para quem assina direto quanto para consultor e corretor, para usar e decidir com calma. Você só é cobrado depois que o teste termina.',
                    'Quanto custa para consultor ou corretor?' => 'O valor depende só de quantos clientes você vincula, e é o mesmo para consultor e para corretor: até '.$prof['first_tier_clients'].' clientes, '.$moneyProf($prof['first_tier_price']).' por mês; até '.$prof['second_tier_clients'].', '.$moneyProf($prof['second_tier_price']).'; depois disso, cada '.$prof['extra_block_clients'].' clientes a mais somam '.$moneyProf($prof['extra_block_price']).'. Não existe escolha de pacote.',
                    'Meus clientes precisam pagar alguma coisa?' => 'Não. Enquanto o vínculo estiver ativo e a sua assinatura em dia, eles usam todas as áreas do Cerne sem custo nenhum.',
                    'O que acontece quando eu chego no limite de clientes?' => 'O Cerne não deixa vincular o próximo cliente até você aumentar o limite, pela página de assinatura. O acesso aos novos clientes é imediato e o novo valor passa a valer a partir da próxima cobrança.',
                    'Como funciona a cobrança?' => 'Mensal, processada pela Asaas, só em cartão de crédito ou Pix. Nenhum dos dois cobra sozinho hoje: você recebe o link de pagamento quando o teste acaba (ou a cada mês seguinte) e paga com um clique. Quem escolhe Pix recebe um aviso por e-mail 3 dias antes de cada vencimento.',
                    'Posso cancelar quando quiser?' => 'Sim, pela sua área de conta, sem precisar falar com ninguém. O acesso é encerrado na hora do cancelamento, sem desconto proporcional do período já pago.',
                    'O consultor ou corretor vê meus dados de Saúde?' => 'Não, nunca, em nenhuma circunstância, mesmo vinculado e com acesso ativo ao resto do seu perfil.',
                    'Minha assinatura cobre meu cônjuge também?' => 'Sim. A assinatura é por perfil financeiro, não por pessoa: se vocês dividem o mesmo perfil de casal, uma assinatura libera o acesso para os dois.',
                    'Qual a diferença entre assinar direto e ter um profissional vinculado?' => 'Se o seu consultor ou corretor já tem assinatura profissional ativa, seu acesso vem sem custo por esse vínculo. Assinar direto só é necessário se você quiser usar o Cerne sozinho, sem nenhum profissional.',
                    'Meus dados são compartilhados com terceiros?' => 'Não, além do necessário para processar a cobrança (Asaas). Você decide o que fica privado entre você e seu cônjuge, e o que o seu consultor ou corretor vê.',
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
            <a href="{{ route('register') }}" class="btn-primary px-6 py-3 text-base">Criar minha conta</a>
            <a href="#planos-profissionais" class="btn-secondary px-6 py-3 text-base">Sou consultor ou corretor</a>
            <a href="{{ route('login') }}" class="btn-ghost px-6 py-3 text-base">Já tenho conta</a>
        </div>
        <div class="mt-6 flex flex-wrap justify-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span class="badge bg-slate-100 dark:bg-slate-800">7 dias grátis</span>
            <span class="badge bg-slate-100 dark:bg-slate-800">Cancele quando quiser</span>
            <span class="badge bg-slate-100 dark:bg-slate-800">Sem taxa de adesão</span>
            <span class="badge bg-slate-100 dark:bg-slate-800">Cartão de crédito ou Pix</span>
        </div>
    </section>

</x-layouts.marketing>
