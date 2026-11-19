@use('App\Support\Money')

<div class="mx-auto max-w-3xl space-y-6" data-busca>
    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Buscar</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Lançamentos, contas fixas, dívidas, objetivos, investimentos, apólices e documentos deste perfil.</p>
    </div>

    <div class="relative">
        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" wire:model.live.debounce.300ms="q" autofocus autocomplete="off" placeholder="Busque por nome, descrição, seguradora, arquivo..." aria-label="Buscar" data-busca-campo
               class="w-full rounded-2xl border border-slate-200 bg-white py-3 pl-11 pr-4 text-base text-slate-900 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-slate-800 dark:text-white">
    </div>

    @if (\App\Services\Assistant\AssistantService::enabled())
        <a href="{{ route('assistant.index') }}" wire:navigate class="block rounded-2xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20" data-ir-assistente>
            Prefere perguntar? <span class="font-medium">Pergunte ao Cerne</span> →
        </a>
    @endif

    @if ($termo === '')
        <p class="text-sm text-slate-500 dark:text-slate-400">Digite pelo menos 2 letras. Dica: aperte <kbd class="rounded border border-slate-300 px-1.5 py-0.5 text-xs dark:border-white/20">/</kbd> em qualquer tela para vir direto aqui.</p>
    @elseif ($curto)
        <p class="text-sm text-slate-500 dark:text-slate-400">Digite pelo menos 2 letras.</p>
    @elseif (count($grupos) === 0)
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-10 text-center dark:border-slate-600 dark:bg-slate-800/40" data-busca-vazia>
            <p class="text-sm text-slate-500 dark:text-slate-400">Nada encontrado para “{{ $termo }}”.</p>
            <p class="mt-1 text-xs text-slate-400">A busca olha nomes e descrições. Nome do segurado e anotações são protegidos e não entram.</p>
        </div>
    @else
        @foreach ($grupos as $g)
            <section data-busca-grupo="{{ $g['titulo'] }}">
                <p class="eyebrow px-1">{{ $g['titulo'] }}</p>
                <ul class="card mt-2 divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($g['itens'] as $i)
                        <li>
                            <a href="{{ $i['url'] }}" wire:navigate class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-white/5">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $i['titulo'] }}</p>
                                    @if ($i['apoio'])
                                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $i['apoio'] }}</p>
                                    @endif
                                </div>
                                @if ($i['valor'] !== null)
                                    <p class="figure shrink-0 text-sm {{ $i['sinal'] === 1 ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-700 dark:text-slate-300' }}">{{ $i['sinal'] === 1 ? '+ ' : ($i['sinal'] === -1 ? '− ' : '') }}{{ Money::format($i['valor']) }}</p>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
</div>
