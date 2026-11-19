<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $relatorio['titulo'] }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'Helvetica', sans-serif; color: #1e293b; font-size: 10.5px; }
        h1 { font-size: 18px; margin: 0 0 2px; color: #0b1d3a; }
        h2 { font-size: 12px; margin: 0 0 2px; color: #0b1d3a; }
        .subtitulo { font-size: 10.5px; color: #64748b; margin: 0 0 12px; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px 4px; }
        .kpi { background: #f1f5f9; padding: 8px 10px; vertical-align: top; }
        .kpi .r { font-size: 8.5px; color: #64748b; }
        .kpi .v { font-size: 14px; font-weight: bold; color: #0b1d3a; margin-top: 2px; }
        .kpi .a { font-size: 8.5px; color: #94a3b8; margin-top: 1px; }
        .secao { margin-top: 16px; page-break-inside: avoid; }
        .sub { font-size: 9.5px; color: #64748b; margin: 0 0 6px; }
        .leg { font-size: 9px; color: #475569; margin: 0 0 4px; }
        .leg span { margin-right: 10px; white-space: nowrap; }
        .leg i { display: inline-block; width: 8px; height: 8px; margin-right: 3px; }
        .nota { font-size: 9.5px; color: #334155; margin: 6px 0 0; }
        table.t { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.t th { text-align: left; font-size: 8px; text-transform: uppercase; color: #94a3b8; border-bottom: 1px solid #cbd5e1; padding: 3px 5px; }
        table.t td { padding: 3px 5px; border-bottom: 1px solid #e2e8f0; font-size: 9px; }
        .rodape { margin-top: 20px; font-size: 8.5px; color: #94a3b8; }
    </style>
</head>
<body>
    <h1>{{ $relatorio['titulo'] }}</h1>
    <p class="subtitulo">{{ $perfil }} · {{ $relatorio['periodo'] }} · gerado em {{ $geradoEm->format('d/m/Y H:i') }}</p>

    @if (count($relatorio['kpis']) > 0)
        <table class="kpis"><tr>
            @foreach ($relatorio['kpis'] as [$rotulo, $valor, $apoio])
                <td class="kpi"><div class="r">{{ $rotulo }}</div><div class="v">{{ $valor }}</div><div class="a">{{ $apoio }}</div></td>
            @endforeach
        </tr></table>
    @endif

    @forelse ($relatorio['secoes'] as $s)
        <div class="secao">
            <h2>{{ $s['titulo'] }}</h2>
            <p class="sub">{{ $s['sub'] }}</p>

            @if (count($s['legenda']) > 0)
                <p class="leg">
                    @foreach ($s['legenda'] as $item)
                        <span><i style="background: {{ $item[0] }}"></i>{{ $item[1] }}@if (isset($item[2])) {{ $item[2] }}@endif</span>
                    @endforeach
                </p>
            @endif

            <img src="{{ App\Support\Charts\SvgCharts::dataUri($s['svg']) }}" width="{{ $s['id'] === 'categorias' ? 200 : 520 }}" alt="{{ $s['titulo'] }}">

            @if (! empty($s['nota']))
                <p class="nota">{{ $s['nota'] }}</p>
            @endif

            <table class="t">
                <thead><tr>@foreach ($s['tabela']['colunas'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($s['tabela']['linhas'] as $linha)
                        <tr>@foreach ($linha as $celula)<td>{{ $celula }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="nota">Não há dados para estas análises no período.</p>
    @endforelse

    <p class="rodape">Gerado pelo Cerne a partir do que está lançado e visível neste perfil. O que um cônjuge marcou como privado não entra nos totais. Os números do cronograma de dívidas vêm do contrato, sem correção aplicada fora do Cerne.</p>
</body>
</html>
