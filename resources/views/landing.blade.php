<x-layouts.marketing title="Cerne — finanças, seguros, documentos e saúde num só lugar">

    {{-- Hero --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
        <div class="max-w-2xl">
            <p class="eyebrow text-brand-700 dark:text-brand-300">Cerne</p>
            <h1 class="mt-3 font-display text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                Sua vida financeira, acompanhada de perto — com quem cuida dela.
            </h1>
            <p class="mt-5 text-lg text-slate-600 dark:text-slate-300">
                Fluxo de caixa, seguros, documentos importantes e saúde, organizados num só lugar.
                Sozinho ou com o consultor/corretor de sua confiança — com a privacidade que você escolher.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="#planos" class="btn-primary px-6 py-3 text-base">Assinar</a>
                <a href="{{ route('login') }}" class="btn-secondary px-6 py-3 text-base">Minha conta</a>
            </div>
        </div>
    </section>

    {{-- Pra quem --}}
    <section class="border-y border-slate-200/70 bg-white/60 py-16 dark:border-white/10 dark:bg-white/5">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 sm:px-6 md:grid-cols-2">
            <div class="card p-6">
                <p class="eyebrow">Pra você</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Assine direto</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Cria sua conta, escolhe o pacote e já começa a organizar suas finanças, seus
                    documentos e sua saúde — sem precisar de convite de ninguém.
                </p>
            </div>
            <div id="profissionais" class="card p-6">
                <p class="eyebrow">Pra profissionais</p>
                <h2 class="mt-2 font-display text-xl font-semibold text-slate-900 dark:text-white">Consultor ou corretor</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Sua assinatura cobre, de graça, todos os clientes que você vincular e mantiver
                    ativos na plataforma. Você escolhe o pacote que eles recebem.
                </p>
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
