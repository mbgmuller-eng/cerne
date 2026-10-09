<div>
    <x-modal wire-model="show">
        @if ($investimento)
            <form wire:submit="save" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $titulo }} · {{ $investimento->displayName() }}</h2>
                    <button type="button" wire:click="close" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                {{-- Onde o ativo está hoje: é a base de qualquer conta abaixo. --}}
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    @if ($investimento->hasPosition())
                        {{ rtrim(rtrim($investimento->quantity, '0'), '.') }} cotas
                        · preço médio R$ {{ number_format((float) $investimento->average_price, 2, ',', '.') }}
                        @if ($investimento->current_price !== null)
                            · cotação R$ {{ number_format((float) $investimento->current_price, 2, ',', '.') }}
                            @if ($investimento->price_date) em {{ $investimento->price_date->format('d/m/Y') }}@endif
                        @endif
                        · valor {{ \App\Support\Money::format($investimento->current_amount) }}
                    @else
                        Valor atual {{ \App\Support\Money::format($investimento->current_amount) }}
                        @if ($investimento->invested_amount !== null) · investido {{ \App\Support\Money::format($investimento->invested_amount) }} @endif
                    @endif
                </p>

                @if ($action === 'initial')
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-white/5 dark:text-slate-300">
                        Este ativo foi cadastrado só pelo valor total. Informe quantas cotas você tem: o valor atual continua o mesmo e o preço médio
                        sai do valor investido dividido pelas cotas, a não ser que você informe outro.
                    </p>
                @endif

                <div class="grid gap-4 @sm:grid-cols-2">
                    @if ($action !== 'quote')
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quantidade de cotas</label>
                            <input type="number" step="any" min="0" wire:model.live.debounce.400ms="quantity" class="input mt-1.5" autocomplete="off">
                            @error('quantity') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            @switch($action)
                                @case('buy') Preço pago por cota @break
                                @case('sell') Preço recebido por cota @break
                                @case('quote') Cotação (preço por cota) @break
                                @default Preço médio por cota <span class="font-normal text-slate-400">(opcional)</span>
                            @endswitch
                        </label>
                        <input type="number" step="any" min="0" wire:model.live.debounce.400ms="price" class="input mt-1.5" autocomplete="off">
                        @error('price') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            {{ match ($action) { 'quote' => 'Data da cotação', 'initial' => 'Data da compra', default => 'Data da operação' } }}
                        </label>
                        <input type="date" wire:model="date" max="{{ now()->toDateString() }}" class="input mt-1.5">
                        @error('date') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @if (in_array($action, ['buy', 'sell'], true))
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Corretagem e taxas <span class="font-normal text-slate-400">(opcional)</span></label>
                            <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="fees" class="input mt-1.5" placeholder="0,00">
                            @error('fees') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                {{-- Prévia: o que muda se salvar agora. --}}
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
                    @if ($action === 'quote')
                        O valor do ativo passa a ser cotas × cotação, e o histórico do mês é atualizado.
                    @else
                        Só o total do patrimônio muda: nenhum saldo de conta é alterado.
                    @endif
                </p>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">Salvar</button>
                </div>
            </form>
        @endif
    </x-modal>
</div>
