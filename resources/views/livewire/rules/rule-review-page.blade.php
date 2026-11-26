@php
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $revisadas = $pareceres->filter(fn ($p) => $p->decision !== null)->count();
@endphp

<div class="space-y-4">
    <div class="card space-y-2 p-5">
        <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Olá, {{ $revisor->name }}</h1>
        <p class="text-sm text-slate-600 dark:text-slate-300">Estas são as regras que o Cerne usa hoje, lidas do próprio sistema. Você não vê dados de nenhuma pessoa: só as regras e o catálogo de exercícios.</p>
        <p class="text-sm text-slate-600 dark:text-slate-300">Para cada regra, escolha <strong>Aprovar</strong> ou <strong>Pedir ajuste</strong> e, se quiser, comente. Nada muda no Cerne na hora: os pedidos viram uma nova versão da regra, e a aprovação vale só para a versão que você revisou.</p>
        <p class="text-xs text-slate-400">CREF informado: {{ $revisor->registry }} · este link vale até {{ $revisor->expires_at->format('d/m/Y') }} · {{ $revisadas }} de {{ count($regras) }} regras com decisão.</p>
    </div>

    @if (session('status'))
        <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    @foreach ($regras as $chave => $regra)
        @php $p = $pareceres->get($chave); @endphp
        <section class="card min-w-0 space-y-3 p-5" wire:key="regra-{{ $chave }}">
            <button type="button" wire:click="open('{{ $chave }}')" aria-expanded="{{ $openRule === $chave ? 'true' : 'false' }}" class="flex w-full items-start justify-between gap-3 text-left">
                <span class="min-w-0 text-sm font-semibold text-slate-900 dark:text-white">{{ $regra['title'] }}</span>
                <span @class(['shrink-0 rounded-full px-2 py-0.5 text-xs font-medium', 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300' => $p?->decision === 'approved', 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300' => $p?->decision === 'changes', 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300' => ! $p?->decision])>
                    {{ match ($p?->decision) { 'approved' => 'Aprovada', 'changes' => 'Pede ajuste', default => 'Aguardando' } }}{{ $p?->decision && ! $p->submitted_at ? ' (não enviado)' : '' }}
                </span>
            </button>

            @if ($openRule === $chave)
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ $regra['description'] }}</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-slate-500 dark:text-slate-400">@foreach ($regra['headers'] as $h)<th class="py-1 pr-3">{{ $h }}</th>@endforeach</tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($regra['rows'] as $linha)
                                <tr>@foreach ($linha as $celula)<td class="py-1.5 pr-3 align-top">{{ $celula }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($regra['load'])
                    <div class="space-y-2" data-marcados>
                        <p class="text-sm text-slate-700 dark:text-slate-300">Hoje esta regra marca <strong>{{ $totalMarcados }} de {{ $catalogo }}</strong> exercícios do catálogo. Toque no que <strong>não deveria</strong> ser marcado.</p>
                        <div class="max-w-xs"><label class="{{ $campo }}">Buscar na lista</label><input type="text" wire:model.live.debounce.300ms="search" class="input mt-1.5"></div>
                        <div class="max-h-80 overflow-y-auto rounded-xl border border-slate-100 p-2 dark:border-white/10">
                            @forelse ($marcados as $nome)
                                @php $fora = in_array($nome, $wrong, true); @endphp
                                <button type="button" wire:key="m-{{ md5($chave.$nome) }}" wire:click="toggleWrong(@js($nome))" aria-pressed="{{ $fora ? 'true' : 'false' }}"
                                    @class(['mb-1 mr-1 rounded-full border px-3 py-1 text-xs', 'border-amber-500 bg-amber-100 text-amber-900 line-through dark:bg-amber-500/15 dark:text-amber-300' => $fora, 'border-slate-200 text-slate-700 dark:border-white/10 dark:text-slate-300' => ! $fora])>{{ $nome }}</button>
                            @empty
                                <p class="text-xs text-slate-400">Nada encontrado.</p>
                            @endforelse
                        </div>
                        @if ($wrong !== []) <p class="text-xs text-slate-500 dark:text-slate-400">Marcados como "não deveria": {{ count($wrong) }}.</p> @endif
                        <div><label class="{{ $campo }}">O que falta marcar</label><input type="text" wire:model="missing" class="input mt-1.5" placeholder="Nomes de exercícios ou palavras"></div>
                    </div>
                @endif

                <div><label class="{{ $campo }}">Comentário</label><textarea wire:model="comment" rows="3" class="input mt-1.5" maxlength="4000"></textarea></div>
                <x-form-errors />
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="decide('approved')" class="{{ $decision === 'approved' ? 'btn-primary' : 'btn-secondary' }} px-4 py-2 text-sm">Aprovar como está</button>
                    <button type="button" wire:click="decide('changes')" class="{{ $decision === 'changes' ? 'btn-primary' : 'btn-secondary' }} px-4 py-2 text-sm">Pedir ajuste</button>
                </div>
            @endif
        </section>
    @endforeach

    <div class="card space-y-3 p-5">
        <p class="text-sm text-slate-600 dark:text-slate-300">{{ $rascunhos === 0 ? 'Nenhuma decisão esperando envio.' : ($rascunhos === 1 ? '1 decisão pronta para enviar.' : $rascunhos.' decisões prontas para enviar.') }} Ao enviar, o parecer fica guardado com o seu nome, o CREF e a data.</p>
        @error('submit') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
        <button type="button" wire:click="submit" class="btn-primary px-4 py-2 text-sm">Enviar parecer</button>
    </div>
</div>
