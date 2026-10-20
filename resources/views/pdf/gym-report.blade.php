<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório de evolução — Academia</title>
    <style>
        @page { margin: 26px 30px 34px; }
        body { font-family: 'Helvetica', sans-serif; color: #1e293b; font-size: 10.5px; line-height: 1.35; }
        h1 { font-size: 19px; margin: 0 0 2px; color: #3c3489; }
        h2 { font-size: 13px; margin: 20px 0 6px; color: #3c3489; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
        h3 { font-size: 11.5px; margin: 0; color: #1e293b; }
        .sub { color: #64748b; margin: 0 0 4px; }
        .muted { color: #64748b; }
        .tiny { font-size: 8.5px; color: #94a3b8; }
        table { width: 100%; border-collapse: collapse; }
        .stats td { width: 25%; padding: 8px 10px; border: 1px solid #e2e8f0; background: #f8fafc; vertical-align: top; }
        .stat-n { font-size: 17px; font-weight: bold; color: #3c3489; }
        .stat-l { font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.03em; color: #94a3b8; margin-top: 1px; }
        .stat-d { font-size: 9px; color: #64748b; margin-top: 2px; }
        ul.frases { margin: 6px 0 0; padding-left: 14px; }
        ul.frases li { margin: 2px 0; }
        .col3 td { width: 33.33%; vertical-align: top; padding: 0 8px 0 0; }
        .caixa { border: 1px solid #e2e8f0; padding: 7px 9px; }
        .caixa h4 { margin: 0 0 4px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; }
        .caixa p { margin: 0 0 4px; }
        .bom { color: #047857; font-weight: bold; }
        .atencao { color: #92400e; font-weight: bold; }
        .treino { margin-top: 12px; page-break-inside: avoid; }
        .treino-cab { background: #eef2ff; border: 1px solid #c7d2fe; padding: 6px 9px; }
        table.ex th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; color: #94a3b8; border-bottom: 1px solid #cbd5e1; padding: 4px 5px; }
        table.ex td { padding: 6px 5px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .nome { font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .tag-up { color: #047857; font-weight: bold; }
        .tag-down { color: #b91c1c; font-weight: bold; }
        .rodape { margin-top: 18px; font-size: 8.5px; color: #94a3b8; }
    </style>
</head>
<body>
    @php
        $unidade = ['kg' => 'kg', 'stack' => 'posição', 'reps' => 'reps', 'duration' => 's', 'speed' => 'km/h'];
        $res = $relatorio['summary'];
        $ant = $relatorio['previous'];
        $fmtMarca = fn (array $linha, array $ponto) => $progress->fmt($ponto['value']).' '.($unidade[$linha['metric']] ?? '')
            .(in_array($linha['metric'], ['kg', 'stack']) && $ponto['tiebreak'] ? ' × '.$ponto['tiebreak'] : '');
        $fmtVar = function (?string $pct) {
            if ($pct === null) { return '—'; }
            $n = str_replace('.', ',', $pct);
            return (bccomp($pct, '0', 1) > 0 ? '+' : (bccomp($pct, '0', 1) < 0 ? '−' : '')).ltrim($n, '-').'%';
        };
    @endphp

    <h1>Relatório de evolução — Academia</h1>
    <p class="sub">{{ $membro->name }} · {{ $relatorio['start']->format('d/m/Y') }} a {{ $relatorio['end']->format('d/m/Y') }} · gerado em {{ $geradoEm->format('d/m/Y H:i') }}</p>

    @if ($res['sessions'] === 0)
        <p class="muted" style="margin-top: 20px;">Nenhum treino finalizado nesse período.</p>
    @else
        {{-- Resumo ------------------------------------------------------------- --}}
        <h2>Resumo do período</h2>
        <table class="stats">
            <tr>
                <td>
                    <div class="stat-n">{{ $res['sessions'] }}</div>
                    <div class="stat-l">Treinos realizados</div>
                    @if ($ant['sessions'] > 0)<div class="stat-d">antes: {{ $ant['sessions'] }}</div>@endif
                </td>
                <td>
                    <div class="stat-n">{{ $res['per_week'] }}</div>
                    <div class="stat-l">Treinos por semana</div>
                    <div class="stat-d">média do período</div>
                </td>
                <td>
                    <div class="stat-n">{{ $res['consistency'] }}%</div>
                    <div class="stat-l">Constância</div>
                    <div class="stat-d">{{ $res['weeks_trained'] }} de {{ $res['weeks_total'] }} semanas com treino</div>
                </td>
                <td>
                    <div class="stat-n">{{ $res['avg_minutes'] ? $res['avg_minutes'].' min' : '—' }}</div>
                    <div class="stat-l">Duração média</div>
                    <div class="stat-d">por treino</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="stat-n">{{ number_format($res['sets'], 0, ',', '.') }}</div>
                    <div class="stat-l">Séries feitas</div>
                </td>
                <td>
                    <div class="stat-n">{{ $res['volume_kg'] }} kg</div>
                    <div class="stat-l">Carga total levantada</div>
                    <div class="stat-d">soma de carga × repetições</div>
                </td>
                <td>
                    <div class="stat-n">{{ $res['longest_gap_days'] ? $res['longest_gap_days'].' dias' : '—' }}</div>
                    <div class="stat-l">Maior pausa</div>
                    <div class="stat-d">entre dois treinos</div>
                </td>
                <td>
                    <div class="stat-n">{{ $res['days_since_last'] ?? '—' }}{{ $res['days_since_last'] !== null ? ' dias' : '' }}</div>
                    <div class="stat-l">Desde o último treino</div>
                </td>
            </tr>
        </table>

        @if ($relatorio['insights'] !== [])
            <ul class="frases">
                @foreach ($relatorio['insights'] as $frase)<li>{{ $frase }}</li>@endforeach
            </ul>
        @endif

        {{-- Frequência --------------------------------------------------------- --}}
        <h2>Frequência: treinos por {{ $relatorio['weekly_unit'] }}</h2>
        <img src="{{ \App\Support\GymReportCharts::dataUri(\App\Support\GymReportCharts::bars($relatorio['weekly'], 700, 120)) }}" width="700" height="120" alt="">

        {{-- Destaques ---------------------------------------------------------- --}}
        @php $d = $relatorio['highlights']; @endphp
        @if ($d['evolution'] || $d['records'] || $d['attention'])
            <h2>Destaques</h2>
            <table class="col3"><tr>
                <td>
                    <div class="caixa">
                        <h4>Maiores evoluções</h4>
                        @forelse ($d['evolution'] as $l)
                            <p><span class="nome">{{ $l['exercise']->name }}</span><br>
                                <span class="bom">{{ $fmtVar($l['change_pct']) }}</span>
                                <span class="muted">({{ $fmtMarca($l, $l['first']) }} → {{ $fmtMarca($l, $l['last']) }})</span></p>
                        @empty
                            <p class="muted">Ainda não há exercício com dois treinos ou mais e melhora.</p>
                        @endforelse
                    </div>
                </td>
                <td>
                    <div class="caixa">
                        <h4>Recordes pessoais</h4>
                        @forelse ($d['records'] as $l)
                            <p><span class="nome">{{ $l['exercise']->name }}</span><br>
                                <span class="bom">{{ $fmtMarca($l, $l['best']) }}</span> <span class="muted">em {{ $l['best']['date']->format('d/m/Y') }}</span></p>
                        @empty
                            <p class="muted">Nenhum recorde novo neste período.</p>
                        @endforelse
                    </div>
                </td>
                <td>
                    <div class="caixa">
                        <h4>Pedem atenção</h4>
                        @forelse ($d['attention'] as $l)
                            <p><span class="nome">{{ $l['exercise']->name }}</span><br>
                                @if ($l['status'] === 'raise')
                                    <span class="bom">Hora de subir a carga.</span>
                                @else
                                    <span class="atencao">Sem evolução há {{ $l['plateau'] }} treinos.</span>
                                @endif
                            </p>
                        @empty
                            <p class="muted">Nenhum exercício parado.</p>
                        @endforelse
                    </div>
                </td>
            </tr></table>
        @endif

        {{-- Por treino --------------------------------------------------------- --}}
        <h2>Cada treino, exercício por exercício</h2>
        @foreach ($relatorio['workouts'] as $treino)
            <div class="treino">
                <div class="treino-cab">
                    <h3>{{ $treino['name'] }}@if ($treino['focus']) <span class="muted" style="font-weight: normal;">· {{ $treino['focus'] }}</span>@endif</h3>
                    <div class="tiny">
                        @if ($treino['plan']){{ $treino['plan'] }} · @endif
                        {{ $treino['sessions'] }} {{ $treino['sessions'] === 1 ? 'treino' : 'treinos' }} no período · último em {{ $treino['last_done']->format('d/m/Y') }}
                    </div>
                </div>
                <table class="ex">
                    <thead>
                        <tr>
                            <th>Exercício</th>
                            <th>Evolução</th>
                            <th class="num">Saiu de</th>
                            <th class="num">Chegou a</th>
                            <th class="num">Variação</th>
                            <th class="num">Melhor marca</th>
                            <th class="num">Treinos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($treino['exercises'] as $l)
                            <tr>
                                <td style="width: 24%;">
                                    <div class="nome">{{ $l['exercise']->name }}</div>
                                    <div class="tiny">
                                        {{ $l['exercise']->muscle_group->label() }}@if ($l['equipment']) · {{ implode(', ', $l['equipment']) }}@endif
                                    </div>
                                    @if ($l['status'] === 'raise')<div class="tiny bom">Hora de subir a carga</div>@elseif ($l['status'] === 'plateau')<div class="tiny atencao">Sem evolução há {{ $l['plateau'] }} treinos</div>@endif
                                </td>
                                <td style="width: 17%;">
                                    <img src="{{ \App\Support\GymReportCharts::dataUri(\App\Support\GymReportCharts::spark($l['values'], 110, 30)) }}" width="110" height="30" alt="">
                                </td>
                                <td class="num">{{ $fmtMarca($l, $l['first']) }}<div class="tiny">{{ $l['first']['date']->format('d/m/Y') }}</div></td>
                                <td class="num">{{ $fmtMarca($l, $l['last']) }}<div class="tiny">{{ $l['last']['date']->format('d/m/Y') }}</div></td>
                                <td class="num">
                                    @if ($l['sessions_count'] < 2)
                                        <span class="tiny">1 treino</span>
                                    @else
                                        <span class="{{ $l['change_pct'] !== null && bccomp($l['change_pct'], '0', 1) > 0 ? 'tag-up' : (($l['change_pct'] !== null && bccomp($l['change_pct'], '0', 1) < 0) ? 'tag-down' : 'muted') }}">{{ $fmtVar($l['change_pct']) }}</span>
                                    @endif
                                </td>
                                <td class="num">{{ $fmtMarca($l, $l['best']) }}@if ($l['record'])<div class="tiny bom">recorde</div>@endif</td>
                                <td class="num">{{ $l['sessions_count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif

    <p class="rodape">
        Como ler: "Saiu de" e "Chegou a" são a melhor série do primeiro e do último treino do período; "Variação" compara as duas. A linha mostra um ponto por treino.
        Recorde = superou tudo o que você tinha feito antes do período. Constância = semanas com ao menos um treino. Sem evolução = {{ \App\Services\GymProgressService::PLATEAU_AFTER }} treinos seguidos sem superar a melhor marca (na mesma máquina).
        Só treinos finalizados entram. Gerado pelo Cerne.
    </p>
</body>
</html>
