<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Investimentos e imposto de renda {{ $relatorio['year'] }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'Helvetica', sans-serif; color: #1e293b; font-size: 10.5px; }
        h1 { font-size: 18px; margin: 0 0 2px; color: #0b1d3a; }
        h2 { font-size: 12px; margin: 20px 0 4px; color: #0b1d3a; }
        .subtitulo { font-size: 10.5px; color: #64748b; margin: 0 0 8px; }
        .aviso { font-size: 9px; color: #92400e; background: #fffbeb; padding: 6px 8px; margin: 8px 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { text-align: left; font-size: 8.5px; text-transform: uppercase; color: #94a3b8; border-bottom: 1px solid #cbd5e1; padding: 4px 6px; }
        td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1px solid #94a3b8; }
        .nota { font-size: 9px; color: #64748b; margin: 2px 0 0; }
        .rodape { margin-top: 22px; font-size: 8.5px; color: #94a3b8; }
    </style>
</head>
<body>
    @php
        $n = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
        $q = fn (string $v) => rtrim(rtrim($v, '0'), '.') ?: '0';
        $mesesNome = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    @endphp

    <h1>Investimentos e imposto de renda · {{ $relatorio['year'] }}</h1>
    <p class="subtitulo">{{ $pessoa }} · gerado em {{ $geradoEm->format('d/m/Y H:i') }}</p>
    <p class="aviso">Conferência com o que está registrado no Cerne. Não calcula DARF, não compensa prejuízos, não separa day trade e não substitui o seu contador.</p>

    <h2>Bens e direitos (custo de aquisição)</h2>
    @if ($relatorio['positions'] === [])
        <p class="nota">Nenhuma posição em cotas em 31/12/{{ $relatorio['year'] }}.</p>
    @else
        <table>
            <thead>
                <tr><th>Ativo</th><th class="num">Cotas</th><th class="num">Preço médio</th><th class="num">Custo em 31/12/{{ $relatorio['year'] }}</th><th class="num">Custo em 31/12/{{ $relatorio['year'] - 1 }}</th></tr>
            </thead>
            <tbody>
                @foreach ($relatorio['positions'] as $p)
                    <tr>
                        <td>{{ $p['investment']->displayName() }} <span class="nota">· {{ $p['investment']->asset_class->label() }}</span></td>
                        <td class="num">{{ $q($p['quantity']) }}</td>
                        <td class="num">{{ $n($p['average_price']) }}</td>
                        <td class="num">{{ $n($p['cost']) }}</td>
                        <td class="num">{{ $n($p['previous_cost']) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="3">Total</td><td class="num">{{ $n($relatorio['positions_cost']) }}</td><td class="num">{{ $n($relatorio['previous_cost']) }}</td></tr>
            </tbody>
        </table>
    @endif

    <h2>Proventos recebidos</h2>
    @if ($relatorio['incomes'] === [])
        <p class="nota">Nenhum provento registrado no ano.</p>
    @else
        <table>
            <thead>
                <tr><th>Natureza</th><th>Ativo</th><th class="num">Bruto</th><th class="num">Imposto retido</th><th class="num">Líquido</th></tr>
            </thead>
            <tbody>
                @foreach ($relatorio['incomes'] as $p)
                    <tr>
                        <td>{{ $p['label'] }}</td>
                        <td>{{ $p['investment']->displayName() }}</td>
                        <td class="num">{{ $n($p['gross']) }}</td>
                        <td class="num">{{ $n($p['tax']) }}</td>
                        <td class="num">{{ $n($p['net']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="nota">Amortização reduz o custo da aplicação e não é rendimento.</p>
    @endif

    <h2>Vendas do ano</h2>
    @if ($relatorio['months'] === [])
        <p class="nota">Nenhuma venda registrada no ano.</p>
    @else
        @foreach ($relatorio['months'] as $mes)
            <p style="margin: 10px 0 2px; font-weight: bold;">{{ $mesesNome[$mes['month']] }}</p>
            @foreach ($mes['groups'] as $g)
                <p class="nota">
                    {{ $g['label'] }}: vendeu {{ $n($g['sold']) }}
                    @if ($g['limit']) (limite de isenção {{ $n($g['limit']) }}: {{ $g['exempt'] ? 'dentro do limite' : 'acima do limite' }}) @else (sem isenção) @endif
                    · resultado {{ $n($g['result']) }}
                </p>
            @endforeach
            <table>
                <thead>
                    <tr><th>Data</th><th>Ativo</th><th class="num">Cotas</th><th class="num">Valor líquido</th><th class="num">Custo</th><th class="num">Resultado</th></tr>
                </thead>
                <tbody>
                    @foreach ($mes['sales'] as $v)
                        <tr>
                            <td>{{ $v['date']->format('d/m/Y') }}</td>
                            <td>{{ $v['investment']->displayName() }}</td>
                            <td class="num">{{ $q($v['quantity']) }}</td>
                            <td class="num">{{ $n($v['net']) }}</td>
                            <td class="num">{{ $n($v['cost']) }}</td>
                            <td class="num">{{ $n($v['result']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif

    <p class="rodape">Cerne · relatório de conferência, ano-base {{ $relatorio['year'] }}.</p>
</body>
</html>
