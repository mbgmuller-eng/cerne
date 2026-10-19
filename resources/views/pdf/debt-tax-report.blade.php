<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Dívidas e ônus reais {{ $relatorio['year'] }}</title>
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
    @php $n = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.'); @endphp

    <h1>Dívidas e ônus reais · {{ $relatorio['year'] }}</h1>
    <p class="subtitulo">{{ $pessoa }} · gerado em {{ $geradoEm->format('d/m/Y H:i') }}</p>
    <p class="aviso">Conferência com o que está registrado no Cerne, não a declaração. O saldo vem do cronograma das parcelas, sem correção que o banco aplique por fora. Dívida do casal aparece para os dois: declare conforme a titularidade do contrato. Confirme com o informe do credor e com o seu contador.</p>

    <h2>Situação em 31/12</h2>
    @if ($relatorio['rows'] === [])
        <p class="nota">Nenhuma dívida com cronograma devida em 31/12/{{ $relatorio['year'] - 1 }} ou 31/12/{{ $relatorio['year'] }}.</p>
    @else
        <table>
            <thead>
                <tr><th>Dívida</th><th>Credor</th><th>CPF ou CNPJ</th><th class="num">31/12/{{ $relatorio['year'] - 1 }}</th><th class="num">31/12/{{ $relatorio['year'] }}</th></tr>
            </thead>
            <tbody>
                @foreach ($relatorio['rows'] as $l)
                    <tr>
                        <td>{{ $l['debt']->name }}@if ($l['contracted_on'])<br><span class="nota">contratada em {{ $l['contracted_on']->format('d/m/Y') }}{{ $l['contract_estimated'] ? ' (estimada)' : '' }}</span>@endif</td>
                        <td>{{ $l['creditor'] ?: '—' }}</td>
                        <td>{{ $l['document'] ?: '—' }}</td>
                        <td class="num">{{ $n($l['previous']) }}</td>
                        <td class="num">{{ $n($l['current']) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="3">Total</td><td class="num">{{ $n($relatorio['total_previous']) }}</td><td class="num">{{ $n($relatorio['total_current']) }}</td></tr>
            </tbody>
        </table>
    @endif

    @if ($relatorio['unscheduled'] !== [])
        <h2>Sem cronograma (fora dos totais)</h2>
        <p class="nota">Dívidas ainda sem acordo: o saldo é o que foi informado, na data informada.</p>
        <table>
            <thead><tr><th>Dívida</th><th>Credor</th><th>CPF ou CNPJ</th><th class="num">Saldo informado</th><th>Informado em</th></tr></thead>
            <tbody>
                @foreach ($relatorio['unscheduled'] as $l)
                    <tr>
                        <td>{{ $l['debt']->name }}</td>
                        <td>{{ $l['debt']->creditor ?: '—' }}</td>
                        <td>{{ $l['debt']->creditor_document ?: '—' }}</td>
                        <td class="num">{{ $n($l['balance']) }}</td>
                        <td>{{ $l['updated_on']?->format('d/m/Y') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="rodape">Cerne · apoio à declaração do imposto de renda.</p>
</body>
</html>
