@use('App\Enums\TransactionType')

<div>
    <x-modal wire-model="show">
        @if ($investimento)
            <form wire:submit="save" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $titulo }} · {{ $investimento->displayName() }}</h2>
                    <button type="button" wire:click="close" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    {{ rtrim(rtrim($investimento->quantity, '0'), '.') }} cotas · preço médio R$ {{ number_format((float) $investimento->average_price, 2, ',', '.') }}
                </p>

                <div class="grid gap-4 @sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                        <select wire:model.live="type" class="select mt-1.5 w-full">
                            @foreach ($tipos as $tipo)
                                <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                            @endforeach
                        </select>
                        @error('type') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $action === 'income' ? 'Data do recebimento' : 'Data do evento' }}</label>
                        <input type="date" wire:model="date" max="{{ now()->toDateString() }}" class="input mt-1.5">
                        @error('date') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @if ($action === 'income')
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor bruto (R$)</label>
                            <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="gross" class="input mt-1.5" autocomplete="off">
                            @error('gross') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                                Imposto retido (R$) <span class="font-normal text-slate-400">(opcional)</span>
                            </label>
                            <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="tax" class="input mt-1.5" autocomplete="off">
                            @if ($type === TransactionType::Jcp->value)
                                <button type="button" wire:click="applyJcpTax" class="mt-1 text-xs font-medium text-brand-700 hover:underline dark:text-accent-400">O JCP sai com 15% retido: aplicar</button>
                            @endif
                            @error('tax') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div class="@sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Creditar o valor líquido na conta <span class="font-normal text-slate-400">(opcional)</span></label>
                            <select wire:model="accountId" class="select mt-1.5 w-full">
                                <option value="">Só registrar no histórico do ativo</option>
                                @foreach ($accounts as $conta)
                                    <option value="{{ $conta->id }}">{{ $conta->displayName() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <div class="@sm:col-span-2">
                            <p class="mb-1.5 text-xs font-medium text-slate-500 dark:text-slate-400">Proporção</p>
                            <div class="flex flex-wrap items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="from" class="input w-24" autocomplete="off" aria-label="Quantidade antes">
                                <span>{{ $type === TransactionType::Split->value ? 'cada cota vira' : 'cada cotas viram' }}</span>
                                <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="to" class="input w-24" autocomplete="off" aria-label="Quantidade depois">
                                <span>{{ $type === TransactionType::Split->value ? 'cotas' : 'cota' }}</span>
                            </div>
                            @error('from') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            @error('to') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                @if ($preview)
                    <dl class="space-y-1 rounded-lg border border-slate-100 px-3 py-2 text-xs dark:border-white/10">
                        @foreach ($preview as $rotulo => $valor)
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">{{ $rotulo }}</dt>
                                <dd class="font-medium tabular-nums text-slate-800 dark:text-slate-200">{{ $valor }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                <p class="text-xs text-slate-400">
                    @if ($action === 'income')
                        @if ($type === TransactionType::Amortization->value)
                            A amortização devolve parte do capital: ela reduz o custo de aquisição e o preço médio, que é o que a declaração de imposto de renda pede.
                        @else
                            O provento não muda a quantidade nem o preço médio.
                        @endif
                    @else
                        A quantidade muda na proporção, o preço médio na proporção inversa, e o custo total continua o mesmo. A cotação guardada acompanha, para o valor do ativo não mudar.
                    @endif
                </p>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">Salvar</button>
                </div>
            </form>
        @endif
    </x-modal>
</div>
