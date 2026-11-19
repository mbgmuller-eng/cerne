{{--
    Corpo de um relatório de análises (cartões de números e uma seção por gráfico). A tela de cada área só põe o cabeçalho e os filtros em volta;
    o PDF lê a MESMA estrutura (ver App\Services\Analytics). `$abertas` e `$categoriaAberta` só existem no fluxo de caixa (rosca clicável).
--}}
@php $abertas ??= null; $categoriaAberta ??= ''; @endphp

@if (count($relatorio['kpis']) > 0)
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-analise-kpis>
        @foreach ($relatorio['kpis'] as [$rotulo, $valor, $apoio])
            <div class="card p-4">
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $rotulo }}</p>
                <p class="figure mt-1 text-lg font-medium text-slate-900 sm:text-xl dark:text-white">{{ $valor }}</p>
                <p class="mt-0.5 text-xs text-slate-400">{{ $apoio }}</p>
            </div>
        @endforeach
    </div>
@endif

@forelse ($relatorio['secoes'] as $s)
    <section class="card p-5 sm:p-6" data-analise="{{ $s['id'] }}">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $s['titulo'] }}</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $s['sub'] }}</p>

        @if ($s['id'] === 'categorias')
            <div class="mt-4 grid items-center gap-6 sm:grid-cols-[13rem_minmax(0,1fr)]">
                <div class="mx-auto w-52 max-w-full [&>svg]:h-auto [&>svg]:w-full">{!! $s['svg'] !!}</div>
                <ul class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($s['legenda'] as $item)
                        @php [$cor, $nome] = $item; $valorTexto = $item[2] ?? ''; $id = $item[3] ?? null; @endphp
                        <li>
                            @if ($id)
                                <button type="button" wire:click="abrirCategoria('{{ $id }}')" aria-pressed="{{ $categoriaAberta === $id ? 'true' : 'false' }}" data-abrir-categoria
                                        class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-white/5 {{ $categoriaAberta === $id ? 'bg-slate-50 dark:bg-white/5' : '' }}">
                                    <span class="flex min-w-0 items-center gap-2 text-slate-800 dark:text-slate-200"><i class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm" style="background: {{ $cor }}"></i><span class="truncate">{{ $nome }}</span></span>
                                    <span class="figure shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ $valorTexto }}</span>
                                </button>
                            @else
                                <div class="flex items-center justify-between gap-3 px-2 py-2 text-sm">
                                    <span class="flex min-w-0 items-center gap-2 text-slate-800 dark:text-slate-200"><i class="inline-block h-2.5 w-2.5 shrink-0 rounded-sm" style="background: {{ $cor }}"></i><span class="truncate">{{ $nome }}</span></span>
                                    <span class="figure shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ $valorTexto }}</span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($abertas)
                <div class="mt-4 rounded-xl bg-slate-50 p-4 dark:bg-white/5" data-subcategorias>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Subcategorias de {{ $abertas['categoria'] }}</p>
                    <ul class="mt-2 divide-y divide-slate-200/70 dark:divide-white/10">
                        @foreach ($abertas['linhas'] as $l)
                            <li class="flex items-center justify-between gap-3 py-1.5 text-sm">
                                <span class="truncate text-slate-800 dark:text-slate-200">{{ $l['nome'] }}</span>
                                <span class="figure shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ App\Support\Money::format($l['valor']) }} · {{ number_format($l['pct'], 0) }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @else
            @if (count($s['legenda']) > 0)
                <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
                    @foreach ($s['legenda'] as $item)
                        <li class="flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $item[0] }}"></i>{{ $item[1] }}@if (isset($item[2])) <span class="text-slate-400">{{ $item[2] }}</span>@endif</li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-3 overflow-x-auto"><div class="min-w-[32rem] [&>svg]:h-auto [&>svg]:w-full">{!! $s['svg'] !!}</div></div>
        @endif

        @if (! empty($s['nota']))
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-300" data-analise-nota>{{ $s['nota'] }}</p>
        @endif

        <details class="mt-3 text-xs">
            <summary class="cursor-pointer text-slate-500 hover:text-slate-700 dark:text-slate-400">Ver em tabela</summary>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[24rem] text-left">
                    <thead>
                        <tr class="text-slate-400">
                            @foreach ($s['tabela']['colunas'] as $coluna)
                                <th class="py-1.5 pr-3 font-medium">{{ $coluna }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($s['tabela']['linhas'] as $linha)
                            <tr>
                                @foreach ($linha as $celula)
                                    <td class="py-1.5 pr-3 text-slate-700 dark:text-slate-300">{{ $celula }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </section>
@empty
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40" data-analise-vazia>
        <p class="text-sm text-slate-600 dark:text-slate-300">Ainda não há dados para estas análises.</p>
        <p class="mt-1 text-xs text-slate-400">Os gráficos aparecem quando houver lançamentos no período.</p>
    </div>
@endforelse
