<div class="mx-auto max-w-2xl space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Assinatura</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Escolha o pacote que cobre o que você precisa.</p>
    </div>

    @if (session('status'))
        <div class="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">
            {{ session('status') }}
        </div>
    @endif

    @if ($assinaturaAtual)
        <section class="card space-y-3 p-5">
            <p class="eyebrow">Sua assinatura</p>
            <x-subscription-summary :subscription="$assinaturaAtual">
                <x-slot:actions>
                    @if ($temAcessoAtivo)
                        <button
                            type="button"
                            wire:click="cancelar"
                            wire:confirm="Cancelar sua assinatura? O acesso é encerrado na hora, sem prorata do período já pago."
                            wire:loading.attr="disabled"
                            class="btn-ghost mt-2 px-0 text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                        >
                            Cancelar assinatura
                        </button>
                    @endif
                </x-slot:actions>
            </x-subscription-summary>
        </section>
    @endif

    @if (! $temAcessoAtivo)
        <div class="space-y-4">
            <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
                7 dias grátis em qualquer pacote. Você só é cobrado depois que o teste acabar.
            </p>

            @if ($souProfissional)
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Isso libera o pacote pra você e pra todos os seus clientes vinculados e ativos.
                </p>
            @endif

            <div class="card p-5">
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">CPF ou CNPJ</label>
                <input type="text" wire:model="cpfCnpj" class="input mt-1.5" placeholder="Só números" maxlength="18">
                <p class="mt-1 text-xs text-slate-400">Exigido pela Asaas (nossa processadora de pagamento) pra emitir a cobrança.</p>
                @error('cpfCnpj') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

                <label class="mt-4 block text-xs font-medium text-slate-500 dark:text-slate-400">Forma de pagamento</label>
                <div class="mt-1.5 grid grid-cols-2 gap-2">
                    @foreach (\App\Enums\PaymentMethod::cases() as $metodo)
                        <label @class([
                            'cursor-pointer rounded-lg border px-3 py-2 text-center text-sm font-medium',
                            'border-brand-700 bg-brand-50 text-brand-900 dark:border-brand-500 dark:bg-brand-500/10 dark:text-brand-200' => $metodoPagamento === $metodo->value,
                            'border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-400' => $metodoPagamento !== $metodo->value,
                        ])>
                            <input type="radio" wire:model="metodoPagamento" value="{{ $metodo->value }}" class="sr-only">
                            {{ $metodo->label() }}
                        </label>
                    @endforeach
                </div>
                @if ($metodoPagamento === 'pix')
                    <p class="mt-1.5 text-xs text-slate-400">A gente avisa por e-mail 3 dias antes de cada vencimento, já que o Pix não tem débito automático.</p>
                @endif
                @error('metodoPagamento') <p class="mt-1.5 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-3 @sm:grid-cols-3">
                @foreach ($bundles as $pacote)
                    <div class="card flex flex-col gap-2 p-5" wire:key="pacote-{{ $pacote->value }}">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $pacote->label() }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ collect($pacote->modules())->map(fn ($m) => $m->label())->join(' · ') }}
                        </p>
                        <p class="mt-auto text-lg font-semibold text-slate-900 dark:text-white">
                            {{ \App\Support\Money::format(config('billing.prices.'.$pacote->value)) }}
                            <span class="text-xs font-normal text-slate-400">/mês</span>
                        </p>
                        <button type="button" wire:click="assinar('{{ $pacote->value }}')" wire:loading.attr="disabled" class="btn-primary w-full">
                            Assinar
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
