{{-- Calendário do mês da Agenda de Saúde. Usa as variáveis da tela (calendario, dia, membros, ehCasal, coresDosMembros). --}}
    {{-- Calendário do mês --------------------------------------------------- --}}
    <section class="card space-y-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="eyebrow">Calendário</p>
            <div class="flex items-center gap-1">
                <button type="button" wire:click="previousMonth" class="btn-secondary px-2.5 py-1 text-xs" aria-label="Mês anterior">←</button>
                <span class="min-w-32 text-center text-sm font-medium text-slate-800 first-letter:uppercase dark:text-slate-200">{{ $calendario['rotulo'] }}</span>
                <button type="button" wire:click="nextMonth" class="btn-secondary px-2.5 py-1 text-xs" aria-label="Próximo mês">→</button>
                <button type="button" wire:click="goToday" class="btn-ghost ml-1 px-2 py-1 text-xs">Hoje</button>
            </div>
        </div>

        <div>
            <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-medium uppercase tracking-wide text-slate-400">
                @foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $nomeDia)
                    <span>{{ $nomeDia }}</span>
                @endforeach
            </div>
            <div class="mt-1 grid grid-cols-7 gap-1">
                @foreach ($calendario['semanas'] as $semana)
                    @foreach ($semana as $celula)
                        <button
                            type="button"
                            wire:click="selectDay('{{ $celula['data'] }}')"
                            wire:key="cal-{{ $celula['data'] }}"
                            aria-label="{{ $celula['rotulo'] }}"
                            aria-pressed="{{ $celula['selecionado'] ? 'true' : 'false' }}"
                            @class([
                                'flex h-12 flex-col items-center justify-start gap-1 rounded-lg pt-1.5 text-sm transition sm:h-14',
                                'text-slate-800 dark:text-slate-200' => $celula['doMes'],
                                'text-slate-300 dark:text-slate-600' => ! $celula['doMes'],
                                'bg-saude-200/50 ring-2 ring-saude-800 dark:bg-saude-800/30 dark:ring-saude-200' => $celula['selecionado'],
                                'hover:bg-slate-100 dark:hover:bg-white/5' => ! $celula['selecionado'],
                                'font-semibold ring-1 ring-slate-300 dark:ring-white/20' => $celula['hoje'] && ! $celula['selecionado'],
                            ])
                        >
                            <span>{{ $celula['dia'] }}</span>
                            @if (count($celula['marcas']) > 0)
                                <span class="flex h-2 items-center justify-center gap-0.5" aria-hidden="true">
                                    @foreach (array_slice($celula['marcas'], 0, 4) as $marca)
                                        @if ($marca['tipo'] === 'consulta')
                                            <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $marca['cor'] }}"></span>
                                        @else
                                            <span class="h-1.5 w-1.5 rounded-full border-[1.5px] bg-transparent" style="border-color: {{ $marca['cor'] }}"></span>
                                        @endif
                                    @endforeach
                                </span>
                            @endif
                        </button>
                    @endforeach
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">
            <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-slate-500"></span> Consulta ou exame</span>
            <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full border-[1.5px] border-slate-500"></span> Item de cuidado</span>
            @if ($ehCasal)
                @foreach ($membros as $membro)
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" style="background-color: {{ $coresDosMembros[$membro->id] }}"></span> {{ $membro->name }}</span>
                @endforeach
            @endif
        </div>

        @if ($dia)
            <div class="space-y-3 border-t border-slate-100 pt-4 dark:border-white/10" wire:key="dia-{{ $dia['data']->toDateString() }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-semibold text-slate-800 first-letter:uppercase dark:text-slate-200">{{ $dia['data']->translatedFormat('l, d/m/Y') }}</p>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="newAppointmentOn('{{ $dia['data']->toDateString() }}')" class="btn-secondary px-3 py-1 text-xs">+ Agendar neste dia</button>
                        <button type="button" wire:click="clearDay" class="btn-ghost px-2 py-1 text-xs">Fechar</button>
                    </div>
                </div>

                @if ($dia['consultas']->isEmpty() && $dia['cuidados']->isEmpty())
                    <p class="text-xs text-slate-400">Nada neste dia.</p>
                @endif

                @foreach ($dia['consultas'] as $consulta)
                    <div class="rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="dia-consulta-{{ $consulta->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                    {{ $consulta->title }}
                                    <span class="ml-1 rounded-full bg-saude-200/40 px-2 py-0.5 text-[10px] font-medium text-saude-800 dark:bg-saude-800/30 dark:text-saude-200">{{ $consulta->kind->label() }}</span>
                                </p>
                                <p class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                    @if ($ehCasal)<span class="inline-block h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $coresDosMembros[$consulta->member_id] ?? '#2563EB' }}"></span>@endif
                                    {{ $consulta->member->name }} · {{ $consulta->scheduled_at->format('H:i') }}
                                </p>
                                <x-appointment-details :consulta="$consulta" />
                                @if (! $consulta->isPast())
                                    <x-calendar-actions :consulta="$consulta" :show-person="$ehCasal" />
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button type="button" wire:click="editAppointment('{{ $consulta->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                            </div>
                        </div>
                    </div>
                @endforeach

                @foreach ($dia['cuidados'] as $item)
                    <div class="rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="dia-cuidado-{{ $item->id }}">
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                            Trocar ou revisar: {{ $item->name }}
                            <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-white/10 dark:text-slate-400">Item de cuidado</span>
                        </p>
                        <p class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                            @if ($ehCasal)<span class="inline-block h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $coresDosMembros[$item->member_id] ?? '#2563EB' }}"></span>@endif
                            {{ $item->member->name }}@if ($item->device_name) · {{ $item->device_name }}@endif · {{ $item->frequencyLabel() }}
                        </p>
                        <p class="mt-1 text-xs"><a href="{{ route('health.care.index') }}" class="font-medium text-saude-800 hover:underline dark:text-saude-200">Abrir em Cuidados e itens</a></p>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

