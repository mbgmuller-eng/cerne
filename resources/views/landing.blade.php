<x-layouts.marketing title="Cerne: finanças, seguros, documentos e saúde num só lugar">

    {{-- Hero --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
        <div class="max-w-2xl">
            <p class="eyebrow text-brand-700 dark:text-brand-300">Cerne</p>
            <h1 class="mt-3 font-display text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                Finanças, seguros, documentos, saúde: tudo o que sustenta sua vida, num só lugar.
            </h1>
            <p class="mt-5 text-lg text-slate-600 dark:text-slate-300">
                Sozinho, ou com quem te ajuda a cuidar dela, com a privacidade que você escolher.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="#planos" class="btn-primary px-6 py-3 text-base">Assinar</a>
                <a href="{{ route('login') }}" class="btn-secondary px-6 py-3 text-base">Minha conta</a>
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
            <x-screenshot-frame x-show="aba === 'painel'" src="{{ asset('images/marketing/painel.jpg') }}" alt="Visão geral do painel financeiro no Cerne" />
            <x-screenshot-frame x-show="aba === 'fluxo'" x-cloak src="{{ asset('images/marketing/fluxo-de-caixa.jpg') }}" alt="Fluxo de caixa do mês no Cerne" />
            <x-screenshot-frame x-show="aba === 'seguros'" x-cloak src="{{ asset('images/marketing/seguros.jpg') }}" alt="Apólices de seguro organizadas no Cerne" />
            <x-screenshot-frame x-show="aba === 'documentos'" x-cloak src="{{ asset('images/marketing/documentos.jpg') }}" alt="Cofre de documentos no Cerne" />
            <x-screenshot-frame x-show="aba === 'saude'" x-cloak src="{{ asset('images/marketing/saude.jpg') }}" alt="Treino de academia acompanhado no Cerne" />
        </div>
    </section>

    {{-- Pra quem --}}
    <section class="border-y border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <div class="card p-6">
                <p class="eyebrow">Pra você</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Assine direto</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Cria sua conta, escolhe o pacote e já começa a acompanhar sua vida inteira,
                    com a privacidade de escolher o que fica só seu e o que é do casal.
                </p>
                <ul class="mt-4 grid gap-2 text-sm text-slate-600 sm:grid-cols-2 dark:text-slate-300">
                    <li><strong class="text-slate-900 dark:text-white">Finanças:</strong> fluxo de caixa, contas fixas e investimentos</li>
                    <li><strong class="text-slate-900 dark:text-white">Seguros:</strong> apólices e datas de renovação</li>
                    <li><strong class="text-slate-900 dark:text-white">Documentos:</strong> CNH, passaporte e exames guardados</li>
                    <li><strong class="text-slate-900 dark:text-white">Saúde:</strong> treino de academia e ficha de emergência</li>
                </ul>
            </div>

            <div id="profissionais" class="mt-10">
                <p class="eyebrow">Pra profissionais</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Cada um vê só a área dele</h2>
                <p class="mt-2 max-w-2xl text-sm text-slate-600 dark:text-slate-300">
                    Sua assinatura cobre, de graça, todos os clientes vinculados e ativos. Nenhum
                    profissional enxerga dado de Saúde, em nenhuma circunstância. Documentos fica
                    visível só pela categoria que o cliente autorizar.
                </p>

                <div class="mt-6 grid gap-5 md:grid-cols-3">
                    <div class="card flex flex-col overflow-hidden">
                        <img src="{{ asset('images/marketing/carteira-consultor.jpg') }}" alt="Painel da carteira de um consultor financeiro" class="h-40 w-full object-cover object-top">
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
                        <img src="{{ asset('images/marketing/carteira-seguros.jpg') }}" alt="Apólices da carteira de um corretor de seguros" class="h-40 w-full object-cover object-top">
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
                        <li>Apólices num só lugar</li>
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
    <section id="planos" class="border-t border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <h2 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Planos</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Um valor mensal, cancele quando quiser.</p>

            <div class="mt-8 grid gap-5 sm:grid-cols-3">
                @foreach (\App\Enums\SubscriptionBundle::cases() as $pacote)
                    <div class="card flex flex-col gap-3 p-6">
                        <h3 class="font-display text-lg font-semibold text-slate-900 dark:text-white">{{ $pacote->label() }}</h3>
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
        </div>
    </section>

    {{-- CTA final --}}
    <section class="mx-auto max-w-6xl px-4 py-16 text-center sm:px-6">
        <h2 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Pronto pra começar?</h2>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('register') }}" class="btn-primary px-6 py-3 text-base">Criar minha conta</a>
            <a href="{{ route('login') }}" class="btn-secondary px-6 py-3 text-base">Já tenho conta</a>
        </div>
    </section>

</x-layouts.marketing>
