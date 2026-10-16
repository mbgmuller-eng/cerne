<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Plano de acompanhamento</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'Helvetica', sans-serif; color: #1e293b; font-size: 10.5px; }
        h1 { font-size: 18px; margin: 0 0 2px; color: #0b1d3a; }
        h2 { font-size: 12px; margin: 20px 0 4px; color: #0b1d3a; }
        .subtitulo { font-size: 10.5px; color: #64748b; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { text-align: left; font-size: 8.5px; text-transform: uppercase; color: #94a3b8; border-bottom: 1px solid #cbd5e1; padding: 4px 6px; }
        td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .nota { font-size: 9px; color: #64748b; margin: 2px 0 0; }
        .bloco { border-bottom: 1px solid #e2e8f0; padding: 6px 0; }
        .bloco p { margin: 2px 0; }
        .rodape { margin-top: 22px; font-size: 8.5px; color: #94a3b8; }
    </style>
</head>
<body>
    <h1>Plano de acompanhamento</h1>
    <p class="subtitulo">{{ $profileName }} · com {{ $consultantName }} · gerado em {{ $generatedAt->format('d/m/Y H:i') }}</p>

    @if ($nextMeeting)
        <h2>Próxima reunião</h2>
        <p>{{ $nextMeeting->scheduled_at->format('d/m/Y \à\s H:i') }} · {{ $nextMeeting->format->label() }}@if ($nextMeeting->location) · {{ $nextMeeting->location }}@endif</p>
        @if ($nextMeeting->agendaItems())
            <p class="nota">Pauta: {{ implode(' · ', $nextMeeting->agendaItems()) }}</p>
        @endif
    @endif

    @if ($stages->isNotEmpty())
        <h2>Passo a passo</h2>
        @foreach ($stages as $etapa)
            @php $feitos = $etapa->items->whereNotNull('done_at')->count(); @endphp
            <div class="bloco">
                <p><strong>{{ $loop->iteration }}. {{ $etapa->title }}</strong> · {{ $feitos }} de {{ $etapa->items->count() }} itens</p>
                @foreach ($etapa->items as $item)
                    <p class="nota">{{ $item->done_at ? '[x]' : '[ ]' }} {{ $item->title }}</p>
                @endforeach
            </div>
        @endforeach
    @endif

    <h2>Tarefas em aberto</h2>
    @if ($openTasks->isEmpty())
        <p class="nota">Nenhuma tarefa em aberto.</p>
    @else
        <table>
            <thead><tr><th>Tarefa</th><th>Responsável</th><th>Prazo</th><th>Situação</th></tr></thead>
            <tbody>
                @foreach ($openTasks as $t)
                    <tr>
                        <td>{{ $t->title }}@if ($t->description)<p class="nota">{{ $t->description }}</p>@endif</td>
                        <td>{{ $t->owner->label() }}</td>
                        <td>{{ $t->due_date?->format('d/m/Y') ?? '—' }}</td>
                        <td>{{ $t->status->label() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($doneTasks->isNotEmpty())
        <h2>Tarefas concluídas</h2>
        <table>
            <thead><tr><th>Tarefa</th><th>Responsável</th><th>Concluída em</th></tr></thead>
            <tbody>
                @foreach ($doneTasks as $t)
                    <tr><td>{{ $t->title }}</td><td>{{ $t->owner->label() }}</td><td>{{ $t->completed_at?->format('d/m/Y') ?? '—' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($meetings->isNotEmpty())
        <h2>Reuniões realizadas</h2>
        @foreach ($meetings as $m)
            <div class="bloco">
                <p><strong>{{ $m->scheduled_at->format('d/m/Y') }}</strong> · {{ $m->format->label() }}</p>
                @if ($m->agendaItems())<p class="nota">Pauta: {{ implode(' · ', $m->agendaItems()) }}</p>@endif
                @if ($m->summary)<p>{!! nl2br(e($m->summary)) !!}</p>@endif
            </div>
        @endforeach
    @endif

    @if ($requests->isNotEmpty())
        <h2>Demandas</h2>
        @foreach ($requests as $r)
            <div class="bloco">
                <p><strong>{{ $r->title }}</strong> · {{ $r->status->label() }} · aberta em {{ $r->created_at->format('d/m/Y') }}</p>
                <p>{!! nl2br(e($r->body)) !!}</p>
                @foreach ($r->comments as $c)
                    <p class="nota">{{ $c->author?->name ?? 'Alguém' }} em {{ $c->created_at->format('d/m') }}: {{ $c->body }}</p>
                @endforeach
            </div>
        @endforeach
    @endif

    @if ($notes->isNotEmpty())
        <h2>Notas compartilhadas</h2>
        @foreach ($notes as $n)
            <div class="bloco">
                <p class="nota">{{ $n->author?->name ?? 'Alguém' }} em {{ $n->created_at->format('d/m/Y') }}</p>
                <p>{!! nl2br(e($n->body)) !!}</p>
            </div>
        @endforeach
    @endif

    <p class="rodape">Plano de acompanhamento gerado pelo Cerne. Reúne o que foi combinado entre você e o seu consultor financeiro.</p>
</body>
</html>
