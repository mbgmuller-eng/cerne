{{-- A que o compromisso está ligado: o check-up e os acompanhamentos. Para consultas, também o atalho para abrir um acompanhamento a partir dela. --}}
<p class="mt-1 flex flex-wrap items-center gap-1.5" data-ligacoes>
    @if ($consulta->routine)
        <a href="{{ route('health.routines.index', ['rotina' => $consulta->routine_id]) }}" class="rounded-full border border-saude-800/30 px-2 py-0.5 text-[10px] font-medium text-saude-800 hover:underline dark:border-saude-200/40 dark:text-saude-200">{{ $consulta->routine->name }}</a>
    @endif
    @foreach ($consulta->followups as $acompanhamento)
        <a href="{{ route('health.followups.show', $acompanhamento->id) }}" class="rounded-full border border-saude-800/30 px-2 py-0.5 text-[10px] font-medium text-saude-800 hover:underline dark:border-saude-200/40 dark:text-saude-200">{{ $acompanhamento->title }}</a>
    @endforeach
    @if ($consulta->kind === \App\Enums\HealthAppointmentKind::Consultation && $consulta->followups->isEmpty())
        <a data-write href="{{ route('health.followups.index', ['consulta' => $consulta->id]) }}" class="text-[11px] text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">+ abrir acompanhamento</a>
    @endif
</p>
