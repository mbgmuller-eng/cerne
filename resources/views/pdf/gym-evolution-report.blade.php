<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Evolução — Academia</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'Helvetica', sans-serif; color: #1e293b; font-size: 11px; }
        h1 { font-size: 18px; margin: 0 0 2px; color: #3c3489; }
        .subtitulo { font-size: 11px; color: #64748b; margin: 0 0 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; color: #94a3b8; border-bottom: 1px solid #cbd5e1; padding: 4px 6px; }
        td { padding: 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .exercicio { font-weight: bold; color: #1e293b; }
        .grupo { color: #64748b; font-size: 9px; }
        .num { text-align: right; white-space: nowrap; }
        .situacao-raise { color: #047857; font-weight: bold; }
        .situacao-plateau { color: #92400e; font-weight: bold; }
        .rodape { margin-top: 24px; font-size: 9px; color: #94a3b8; }
        .vazio { margin-top: 24px; color: #64748b; }
    </style>
</head>
<body>
    <h1>Evolução — Academia</h1>
    <p class="subtitulo">
        {{ $membro->name }} · {{ $inicio->format('d/m/Y') }} a {{ $fim->format('d/m/Y') }}
        · gerado em {{ $geradoEm->format('d/m/Y H:i') }}
    </p>

    @if ($linhas->isEmpty())
        <p class="vazio">Nenhum treino finalizado nesse período.</p>
    @else
        @php $unidade = ['kg' => 'kg', 'stack' => 'posição', 'reps' => 'reps', 'duration' => 's', 'speed' => 'km/h']; @endphp
        <table>
            <thead>
                <tr>
                    <th>Exercício</th>
                    <th class="num">Treinos</th>
                    <th class="num">Primeira marca</th>
                    <th class="num">Última marca</th>
                    <th class="num">Melhor marca</th>
                    <th>Situação</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($linhas as $linha)
                    @php $un = $unidade[$linha['metric']] ?? ''; @endphp
                    <tr>
                        <td>
                            <div class="exercicio">{{ $linha['exercise']->name }}</div>
                            <div class="grupo">{{ $linha['exercise']->muscle_group->label() }}</div>
                        </td>
                        <td class="num">{{ $linha['sessions_count'] }}</td>
                        <td class="num">
                            {{ $progress->fmt($linha['first']['value']) }} {{ $un }}
                            <br><span class="grupo">{{ $linha['first']['date']->format('d/m/Y') }}</span>
                        </td>
                        <td class="num">
                            {{ $progress->fmt($linha['last']['value']) }} {{ $un }}
                            <br><span class="grupo">{{ $linha['last']['date']->format('d/m/Y') }}</span>
                        </td>
                        <td class="num">
                            {{ $progress->fmt($linha['best']['value']) }} {{ $un }}
                            @if (in_array($linha['metric'], ['kg', 'stack']) && $linha['best']['tiebreak'])
                                <br><span class="grupo">× {{ $linha['best']['tiebreak'] }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($linha['status'] === 'raise')
                                <span class="situacao-raise">Subir carga</span>
                            @elseif ($linha['status'] === 'plateau')
                                <span class="situacao-plateau">Platô ({{ $linha['plateau'] }})</span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="rodape">
        Melhor marca = maior carga/repetições/duração registrada no período. Platô = {{ \App\Services\GymProgressService::PLATEAU_AFTER }} treinos seguidos sem superar a melhor marca (na mesma máquina). Gerado pelo Cerne.
    </p>
</body>
</html>
