<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Folha de atividades</title>
    <style>
        @page { margin: 26px 30px 34px; }
        body { font-family: 'Helvetica', sans-serif; color: #1e293b; font-size: 10.5px; line-height: 1.35; }
        h1 { font-size: 18px; margin: 0 0 2px; color: #3c3489; }
        h2 { font-size: 12.5px; margin: 16px 0 5px; color: #3c3489; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
        .sub { color: #64748b; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; border-bottom: 1px solid #94a3b8; padding: 4px 5px; }
        td { padding: 5px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .marca { width: 52px; text-align: center; }
        .caixa { display: inline-block; width: 11px; height: 11px; border: 1px solid #475569; }
        .linha { border-bottom: 1px solid #94a3b8; height: 21px; }
        .opcao { margin-right: 18px; }
        .rodape { margin-top: 16px; font-size: 8.5px; color: #94a3b8; }
    </style>
</head>
<body>
    <h1>Minhas atividades, para a sua orientação</h1>
    <p class="sub">{{ $nome }} · gerado em {{ $geradoEm->format('d/m/Y') }}</p>
    @foreach ($limitacoes as $l)
        <p class="sub">{{ $l->regionLabel() }}: {{ mb_strtolower($l->status->label()) }}.</p>
    @endforeach

    <h2>Minha semana habitual</h2>
    @foreach ($compromissos as $c)
        <div>{{ $c->name() }}: {{ $c->isFixed() ? mb_strtolower($dias[$c->weekday]) : $c->times_per_week.'x por semana' }}, {{ $c->minutes }} min.</div>
    @endforeach
    @if ($diasDaAcademia !== [])
        <div>Academia: {{ implode(', ', $diasDaAcademia) }}, até {{ $minutosPorTreino }} min.</div>
    @endif
    @if ($compromissos->isEmpty() && $diasDaAcademia === [])
        <div class="linha"></div>
    @endif
    @if ($objetivos !== [])
        <p><strong>Meus objetivos, em ordem:</strong> {{ implode('; ', $objetivos) }}.</p>
    @endif

    @if ($treinos->isNotEmpty())
        <h2>Meus exercícios de hoje</h2>
        <table>
            <thead><tr><th>Exercício</th><th class="marca">Pode</th><th class="marca">Adaptar</th><th class="marca">Evitar</th></tr></thead>
            <tbody>
                @foreach ($treinos as $treino)
                    <tr><td colspan="4"><strong>{{ $treino->name }}</strong>@if ($treino->focus) <span class="sub">{{ $treino->focus }}</span>@endif</td></tr>
                    @foreach ($treino->workoutExercises as $item)
                        <tr>
                            <td>{{ $item->exercise->name }} <span class="sub">{{ $item->target_sets }} × {{ $item->target_duration_seconds ? $item->target_duration_seconds.' s' : (collect([$item->target_reps_min, $item->target_reps_max])->filter()->unique()->implode(' a ') ?: 'séries') }}</span></td>
                            <td class="marca"><span class="caixa"></span></td>
                            <td class="marca"><span class="caixa"></span></td>
                            <td class="marca"><span class="caixa"></span></td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Esportes que pratico</h2>
    @php $praticados = $compromissos->map(fn ($c) => $c->name())->unique(); @endphp
    @forelse ($praticados as $p)
        <p>{{ $p }}: <span class="opcao"><span class="caixa"></span> Pode</span><span class="opcao"><span class="caixa"></span> Com cuidado</span><span class="opcao"><span class="caixa"></span> Pausar</span></p>
    @empty
        <div class="linha"></div>
    @endforelse

    <h2>Corrida</h2>
    <p><span class="opcao"><span class="caixa"></span> Pode começar</span><span class="opcao"><span class="caixa"></span> Ainda não</span></p>

    <h2>O que devo evitar</h2>
    <div class="linha"></div><div class="linha"></div><div class="linha"></div>

    <h2>Exercícios que devo fazer (séries, repetições ou tempo e vezes por semana)</h2>
    <div class="linha"></div><div class="linha"></div><div class="linha"></div><div class="linha"></div><div class="linha"></div>

    <p class="rodape">Folha gerada pelo Cerne a partir do que a própria pessoa registrou. Não substitui avaliação profissional.</p>
</body>
</html>
