@props(['data', 'accent' => 'brand', 'eyebrow' => 'Calendário'])

{{--
    Calendário em três visões (mês, semana, dia), no estilo de uma agenda: cada compromisso aparece com o horário e o título, e
    tocar nele abre o detalhe. `data` é o que CalendarBuilder::build() montou (via o trait HasCalendar); os métodos chamados aqui
    (setCalView, previousPeriod, nextPeriod, goToToday, goToDay, openCalendarEvent, closeEvent) são os do trait.

    Slots: `toolbar` (botões extras ao lado da navegação), `legend` (explica as cores) e `detail` (o que mais mostrar no detalhe
    do compromisso aberto; a tela dona do calendário sabe o que é o compromisso).

    Telas estreitas: a grade de horários não cabe em sete colunas, então semana e dia viram uma lista por dia (`sm:hidden`), e
    no mês os compromissos viram pontos coloridos.
--}}
@php
    use App\Enums\CalendarView;

    $visao = CalendarView::from($data['view']);
    $ativo = $accent === 'saude'
        ? 'bg-saude-800 text-white dark:bg-saude-200 dark:text-saude-950'
        : 'bg-brand-800 text-white dark:bg-brand-300 dark:text-slate-900';
    $inativo = 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-white/10 dark:text-slate-300 dark:hover:bg-white/15';
    $marcaHoje = $accent === 'saude' ? 'bg-saude-800 text-white dark:bg-saude-200 dark:text-saude-950' : 'bg-brand-800 text-white dark:bg-brand-300 dark:text-slate-900';
    $semana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    // Um compromisso como "etiqueta" colorida (mês e dia inteiro): barra na cor de quem é, fundo suave.
    $estilo = fn ($e) => $e->hollow
        ? "border: 1.5px dashed {$e->color}; background-color: transparent;"
        : "background-color: {$e->color}26; border-left: 3px solid {$e->color};";
@endphp

<section class="card space-y-4 p-5" wire:key="calendario-{{ $data['view'] }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="eyebrow">{{ $eyebrow }}</p>
        <div class="flex items-center gap-1 text-xs" role="group" aria-label="Como ver o calendário">
            @foreach (CalendarView::cases() as $opcao)
                <button
                    type="button"
                    wire:click="setCalView('{{ $opcao->value }}')"
                    aria-pressed="{{ $visao === $opcao ? 'true' : 'false' }}"
                    @class(['rounded-full px-3 py-1 font-medium transition', $ativo => $visao === $opcao, $inativo => $visao !== $opcao])
                >{{ $opcao->label() }}</button>
            @endforeach
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-1">
            <button type="button" wire:click="previousPeriod" class="btn-secondary px-2.5 py-1 text-xs" aria-label="Período anterior">←</button>
            <button type="button" wire:click="nextPeriod" class="btn-secondary px-2.5 py-1 text-xs" aria-label="Próximo período">→</button>
            <button type="button" wire:click="goToToday" class="btn-ghost ml-1 px-2 py-1 text-xs">Hoje</button>
            <span class="ml-2 text-sm font-medium text-slate-800 first-letter:uppercase dark:text-slate-200">{{ $data['title'] }}</span>
        </div>
        @isset($toolbar)
            <div class="flex items-center gap-2">{{ $toolbar }}</div>
        @endisset
    </div>

    {{-- ------------------------------------------------------------------ mês --}}
    @if ($visao === CalendarView::Month)
        <div>
            <div class="grid grid-cols-7 gap-px text-center text-[10px] font-medium uppercase tracking-wide text-slate-400">
                @foreach ($semana as $nomeDia)
                    <span class="py-1">{{ $nomeDia }}</span>
                @endforeach
            </div>
            <div class="grid grid-cols-7 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 dark:border-white/10 dark:bg-white/10">
                @foreach ($data['weeks'] as $linha)
                    @foreach ($linha as $dia)
                        <div
                            wire:key="mes-{{ $dia['date'] }}"
                            @class([
                                'min-h-16 min-w-0 p-1 sm:min-h-28',
                                'bg-white dark:bg-slate-900' => $dia['in_month'],
                                'bg-slate-50 dark:bg-slate-950' => ! $dia['in_month'],
                            ])
                        >
                            <button
                                type="button"
                                wire:click="goToDay('{{ $dia['date'] }}')"
                                aria-label="{{ $dia['label'] }}{{ $dia['count'] > 0 ? ', '.$dia['count'].' '.($dia['count'] === 1 ? 'compromisso' : 'compromissos') : '' }}"
                                @class([
                                    'flex h-6 w-6 items-center justify-center rounded-full text-xs',
                                    $marcaHoje.' font-semibold' => $dia['today'],
                                    'text-slate-800 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/10' => ! $dia['today'] && $dia['in_month'],
                                    'text-slate-300 hover:bg-slate-100 dark:text-slate-600 dark:hover:bg-white/10' => ! $dia['today'] && ! $dia['in_month'],
                                ])
                            >{{ $dia['day'] }}</button>

                            <div class="mt-1 hidden space-y-0.5 sm:block">
                                @foreach ($dia['visible'] as $e)
                                    <button
                                        type="button"
                                        wire:click="openCalendarEvent('{{ $e->key }}')"
                                        wire:key="chip-{{ $e->key }}"
                                        title="{{ trim($e->timeLabel().' '.$e->title) }}"
                                        @class(['flex w-full min-w-0 flex-col items-start rounded px-1.5 py-0.5 text-left text-[11px] leading-4 text-slate-800 hover:brightness-95 xl:flex-row xl:items-center xl:gap-1 dark:text-slate-100', 'opacity-60' => $e->muted])
                                        style="{{ $estilo($e) }}"
                                    >
                                        @unless ($e->allDay)<span class="shrink-0 text-[10px] font-semibold tabular-nums xl:text-[11px]">{{ $e->start->format('H:i') }}</span>@endunless
                                        <span class="w-full min-w-0 truncate">{{ $e->chipTitle() }}</span>
                                    </button>
                                @endforeach
                                @if ($dia['more'] > 0)
                                    <button type="button" wire:click="goToDay('{{ $dia['date'] }}')" class="px-1.5 text-left text-[11px] font-medium text-slate-500 hover:underline dark:text-slate-400">+{{ $dia['more'] }} mais</button>
                                @endif
                            </div>

                            @if ($dia['count'] > 0)
                                <button type="button" wire:click="goToDay('{{ $dia['date'] }}')" tabindex="-1" aria-hidden="true" class="mt-1 flex w-full flex-wrap justify-center gap-0.5 sm:hidden">
                                    @foreach (array_slice($dia['events'], 0, 4) as $e)
                                        <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $e->color }}"></span>
                                    @endforeach
                                </button>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    @else
        {{-- ---------------------------------------------------------------- semana e dia: grade de horários --}}
        @php
            $colunas = count($data['days']);
            $modelo = '3rem repeat('.$colunas.', minmax(0, 1fr))';
            $horas = $data['hours'];
            $ehDia = $visao === CalendarView::Day;
        @endphp

        <div class="hidden sm:block">
            <div @class(['overflow-x-auto' => ! $ehDia])>
                <div @class(['min-w-[44rem]' => ! $ehDia])>
                    <div class="grid border-b border-slate-200 dark:border-white/10" style="grid-template-columns: {{ $modelo }}">
                        <div></div>
                        @foreach ($data['days'] as $dia)
                            <div class="min-w-0 border-l border-slate-100 py-1.5 text-center dark:border-white/5" wire:key="cab-{{ $dia['date'] }}">
                                <button type="button" wire:click="goToDay('{{ $dia['date'] }}')" class="group inline-flex flex-col items-center" aria-label="{{ $dia['label'] }}">
                                    <span class="text-[10px] font-medium uppercase tracking-wide text-slate-400">{{ $dia['weekday'] }}</span>
                                    <span @class(['mt-0.5 flex h-7 w-7 items-center justify-center rounded-full text-sm', $marcaHoje.' font-semibold' => $dia['today'], 'text-slate-800 group-hover:bg-slate-100 dark:text-slate-200 dark:group-hover:bg-white/10' => ! $dia['today']])>{{ $dia['day'] }}</span>
                                </button>
                            </div>
                        @endforeach
                    </div>

                    @if ($data['has_all_day'])
                        <div class="grid border-b border-slate-200 dark:border-white/10" style="grid-template-columns: {{ $modelo }}">
                            <div class="px-1 py-1 text-right text-[10px] leading-4 text-slate-400">dia todo</div>
                            @foreach ($data['days'] as $dia)
                                <div class="min-w-0 space-y-0.5 border-l border-slate-100 p-1 dark:border-white/5" wire:key="dt-{{ $dia['date'] }}">
                                    @foreach ($dia['all_day'] as $e)
                                        <button
                                            type="button"
                                            wire:click="openCalendarEvent('{{ $e->key }}')"
                                            title="{{ $e->title }}"
                                            @class(['flex w-full min-w-0 items-center rounded px-1.5 py-0.5 text-left text-[11px] leading-4 text-slate-800 hover:brightness-95 dark:text-slate-100', 'opacity-60' => $e->muted])
                                            style="{{ $estilo($e) }}"
                                        ><span class="truncate">{{ $e->chipTitle() }}</span></button>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid" style="grid-template-columns: {{ $modelo }}; height: {{ $horas['height'] }}rem">
                        <div class="relative">
                            @foreach ($horas['rows'] as $i => $hora)
                                <span class="absolute right-1 -translate-y-1/2 text-[10px] tabular-nums text-slate-400" style="top: {{ $i * \App\Support\Calendar\CalendarBuilder::HOUR_REM }}rem; {{ $i === 0 ? 'transform: none;' : '' }}">{{ str_pad((string) $hora, 2, '0', STR_PAD_LEFT) }}:00</span>
                            @endforeach
                        </div>

                        @foreach ($data['days'] as $dia)
                            <div class="relative min-w-0 border-l border-slate-100 dark:border-white/5" wire:key="col-{{ $dia['date'] }}">
                                @foreach ($horas['rows'] as $i => $hora)
                                    <div class="absolute inset-x-0 border-t border-slate-100 dark:border-white/5" style="top: {{ $i * \App\Support\Calendar\CalendarBuilder::HOUR_REM }}rem"></div>
                                @endforeach

                                @if ($dia['today'] && $data['now'] !== null)
                                    <div class="absolute inset-x-0 z-10 border-t-2 border-red-500" style="top: {{ $data['now'] }}rem" aria-hidden="true"><span class="absolute -left-1 -top-1.5 h-2.5 w-2.5 rounded-full bg-red-500"></span></div>
                                @endif

                                @foreach ($dia['timed'] as $slot)
                                    @php $e = $slot['event']; @endphp
                                    <button
                                        type="button"
                                        wire:click="openCalendarEvent('{{ $e->key }}')"
                                        wire:key="bloco-{{ $e->key }}"
                                        title="{{ trim($e->timeLabel().' '.$e->title) }}"
                                        @class(['absolute z-[5] overflow-hidden rounded-md px-1.5 py-1 text-left text-[11px] leading-4 text-slate-800 shadow-sm hover:brightness-95 dark:text-slate-100', 'opacity-60' => $e->muted])
                                        style="top: {{ $slot['top'] }}rem; height: calc({{ $slot['height'] }}rem - 2px); left: calc({{ $slot['left'] }}% + 1px); width: calc({{ $slot['width'] }}% - 3px); {{ $estilo($e) }}"
                                    >
                                        <span class="block truncate font-semibold">{{ $ehDia ? $e->title : $e->chipTitle() }}</span>
                                        <span class="block truncate tabular-nums text-slate-600 dark:text-slate-300">{{ $e->timeLabel() }}</span>
                                        @if ($slot['height'] >= 3.5)
                                            @foreach (array_slice($e->lines, 0, $ehDia ? 4 : 2) as $linha)
                                                <span class="block truncate text-slate-600 dark:text-slate-300">{{ $linha }}</span>
                                            @endforeach
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Celular: uma lista por dia, em vez da grade. --}}
        <div class="space-y-4 sm:hidden">
            @php $comCompromisso = collect($data['days'])->filter(fn ($d) => $d['count'] > 0); @endphp
            @forelse ($comCompromisso as $dia)
                <div wire:key="lista-{{ $dia['date'] }}">
                    <p class="text-xs font-semibold text-slate-500 first-letter:uppercase dark:text-slate-400">{{ $dia['label'] }}</p>
                    <div class="mt-1.5 space-y-1.5">
                        @foreach ($dia['events'] as $e)
                            <button
                                type="button"
                                wire:click="openCalendarEvent('{{ $e->key }}')"
                                @class(['flex w-full items-start gap-3 rounded-lg px-3 py-2 text-left text-slate-800 dark:text-slate-100', 'opacity-60' => $e->muted])
                                style="{{ $estilo($e) }}"
                            >
                                <span class="w-12 shrink-0 text-xs font-semibold tabular-nums">{{ $e->allDay ? 'dia todo' : $e->start->format('H:i') }}</span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium">{{ $e->title }}</span>
                                    @foreach (array_slice($e->lines, 0, 2) as $linha)
                                        <span class="block truncate text-xs text-slate-600 dark:text-slate-300">{{ $linha }}</span>
                                    @endforeach
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="text-xs text-slate-400">Nada {{ $ehDia ? 'neste dia' : 'nesta semana' }}.</p>
            @endforelse
        </div>

        @if ($data['total'] === 0)
            <p class="hidden text-xs text-slate-400 sm:block">Nada {{ $ehDia ? 'neste dia' : 'nesta semana' }}.</p>
        @endif
    @endif

    @isset($legend)
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">{{ $legend }}</div>
    @endisset

    {{-- ------------------------------------------------------------------ detalhe do compromisso --}}
    <x-modal wire-model="eventVisible" max-width="lg">
        @php $aberto = $data['open'] ?? null; @endphp
        @if ($aberto)
            <div class="space-y-4" wire:key="detalhe-{{ $aberto->key }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-white">
                            <span class="inline-block h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $aberto->color }}"></span>
                            <span class="min-w-0">{{ $aberto->title }}</span>
                        </p>
                        @if ($aberto->tag !== '')
                            <span class="mt-1 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $aberto->tag }}</span>
                        @endif
                    </div>
                    <button type="button" wire:click="closeEvent" class="btn-ghost shrink-0 px-2 py-1 text-xs">Fechar</button>
                </div>

                <p class="text-sm text-slate-700 first-letter:uppercase dark:text-slate-300">
                    {{ $aberto->start->translatedFormat('l, j \d\e F \d\e Y') }}
                    · {{ $aberto->allDay ? 'dia todo' : $aberto->timeLabel() }}
                </p>

                @if ($aberto->modalLines() !== [])
                    <ul class="space-y-0.5 text-sm text-slate-600 dark:text-slate-300">
                        @foreach ($aberto->modalLines() as $linha)
                            <li>{{ $linha }}</li>
                        @endforeach
                    </ul>
                @endif

                @isset($detail)
                    {{ $detail }}
                @endisset
            </div>
        @endif
    </x-modal>
</section>
