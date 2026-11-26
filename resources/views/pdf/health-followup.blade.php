<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Resumo de saúde</title>
    <style>
        @page { margin: 26px 30px 34px; }
        body { font-family: 'Helvetica', sans-serif; color: #1e293b; font-size: 10.5px; line-height: 1.35; }
        h1 { font-size: 19px; margin: 0 0 2px; color: #3c3489; }
        h2 { font-size: 13px; margin: 18px 0 6px; color: #3c3489; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
        .sub { color: #64748b; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 0.03em; color: #94a3b8; border-bottom: 1px solid #cbd5e1; padding: 4px 5px; }
        td { padding: 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .data { white-space: nowrap; width: 70px; }
        .tipo { width: 105px; color: #64748b; }
        .nome { font-weight: bold; }
        .texto { white-space: pre-line; }
        .rodape { margin-top: 18px; font-size: 8.5px; color: #94a3b8; }
    </style>
</head>
<body>
    <h1>{{ $acompanhamento->title }}</h1>
    <p class="sub">{{ $acompanhamento->member->name }} · {{ $acompanhamento->status->label() }} · desde {{ $acompanhamento->started_on->format('d/m/Y') }}@if ($acompanhamento->resolved_on) · resolvido em {{ $acompanhamento->resolved_on->format('d/m/Y') }}@endif</p>

    <h2>Linha do tempo</h2>
    @if ($linhas === [])
        <p class="sub">Nada registrado.</p>
    @else
        <table>
            @foreach ($linhas as $linha)
                <tr>
                    <td class="data">{{ $linha['date']?->format('d/m/Y') ?? 'Sem data' }}</td>
                    <td class="tipo">{{ $linha['label'] }}</td>
                    <td>
                        <span class="nome">{{ $linha['title'] }}</span>
                        @if ($linha['text'])<div class="texto">{{ $linha['text'] }}</div>@endif
                        @foreach ($linha['documents'] as $d)<div class="sub">Anexo guardado no Cerne: {{ $d->title }}</div>@endforeach
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($atividades->isNotEmpty() || $acompanhamento->guided_by || $acompanhamento->avoid)
        <h2>Programa de atividades</h2>
        @if ($acompanhamento->guided_by || $acompanhamento->guided_on)
            <p class="sub">Orientado por {{ $acompanhamento->guided_by ?: 'profissional não informado' }}@if ($acompanhamento->guided_on) em {{ $acompanhamento->guided_on->format('d/m/Y') }}@endif.</p>
        @endif
        @if ($acompanhamento->avoid)
            <p class="texto"><span class="nome">Evitar:</span> {{ $acompanhamento->avoid }}</p>
        @endif
        @if ($atividades->isNotEmpty())
            <table>
                <tr><th>Atividade</th><th>Frequência</th><th>Presenças</th><th>Dor média</th><th>Exercícios</th></tr>
                @foreach ($atividades as $a)
                    <tr>
                        <td class="nome">{{ $a->name }}</td>
                        <td>{{ $a->per_week }}x por semana</td>
                        <td>{{ $a->presencas }}@if ($a->total_sessions) de {{ $a->total_sessions }}@endif @if ($a->faltas) ({{ $a->faltas }} {{ $a->faltas === 1 ? 'falta' : 'faltas' }})@endif</td>
                        <td>{{ $a->dor_media === null ? '-' : str_replace('.', ',', (string) round((float) $a->dor_media, 1)) }}</td>
                        <td>@foreach ($a->exercises as $e)<div>{{ $e->name }}@if ($e->dose) {{ $e->dose }}@endif</div>@endforeach</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endif

    @if ($exames['groups'] !== [])
        <h2>Resultados de exames para comparar</h2>
        <p class="sub">Os resultados pedidos neste acompanhamento, com os valores anteriores da pessoa. “Acima” e “abaixo” comparam com a referência do laudo daquela data.</p>
        <table style="font-size: 9.5px">
            <tr>
                <th>Resultado de</th>
                @foreach ($exames['columns'] as $data)<th>{{ \Carbon\CarbonImmutable::parse($data)->format('d/m/Y') }}</th>@endforeach
                <th>Referência atual</th>
            </tr>
            @foreach ($exames['groups'] as $grupo => $linhasDoGrupo)
                <tr><td colspan="{{ count($exames['columns']) + 2 }}" class="sub">{{ $grupo }}</td></tr>
                @foreach ($linhasDoGrupo as $r)
                    <tr>
                        <td class="nome">{{ $r['name'] }} <span class="sub">{{ $r['unit'] }}</span></td>
                        @foreach ($exames['columns'] as $data)
                            @php $c = $r['cells'][$data] ?? null; @endphp
                            <td>{{ $c['value'] ?? '-' }}@if ($c && ! $c['comparable']) {{ $c['unit'] }}@endif @if ($c && $c['flag'] !== '')<div class="nome">{{ $c['flag'] === 'above' ? 'acima' : 'abaixo' }}</div>@endif</td>
                        @endforeach
                        <td class="sub">{{ $r['reference'] ?: '-' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    @endif

    <h2>Ficha de saúde</h2>
    <table>
        <tr><td class="tipo">Alergias</td><td>{{ $alergias->pluck('description')->implode('; ') ?: 'Nenhuma registrada' }}</td></tr>
        <tr><td class="tipo">Doenças</td><td>{{ $doencas->pluck('description')->implode('; ') ?: 'Nenhuma registrada' }}</td></tr>
        <tr><td class="tipo">Remédios em uso</td><td>@forelse ($remedios as $r)<div>{{ implode(' · ', array_filter([$r->name, $r->dose, $r->schedule])) }}</div>@empty Nenhum registrado @endforelse</td></tr>
    </table>

    <p class="rodape">Gerado no Cerne em {{ $geradoEm->format('d/m/Y \à\s H:i') }}, com o que foi registrado pela pessoa e por quem a acompanha. Não é um prontuário nem substitui o laudo.</p>
</body>
</html>
