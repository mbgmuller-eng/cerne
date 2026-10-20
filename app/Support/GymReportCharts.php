<?php

namespace App\Support;

/**
 * Gráficos do relatório da Academia como SVG puro (sem biblioteca): o mesmo desenho serve na tela (inline) e no PDF (como
 * imagem em data URI). É geometria de tela, por isso float aqui não fere a regra do dinheiro.
 */
final class GymReportCharts
{
    private const BRAND = '#3c3489';

    private const MUTED = '#94a3b8';

    /**
     * Barras de treinos por semana (ou mês). Semana sem treino aparece vazia, para a pausa ficar visível.
     *
     * @param  list<array{label: string, count: int}>  $dados
     */
    public static function bars(array $dados, int $largura = 520, int $altura = 130): string
    {
        $n = count($dados);

        if ($n === 0) {
            return '';
        }

        $esq = 18;
        $base = $altura - 22;
        $topo = 16;
        $maximo = max(1, ...array_column($dados, 'count'));
        $slot = ($largura - $esq - 6) / $n;
        $larguraBarra = max(3, min(26, $slot * 0.62));
        $passo = max(1, (int) ceil($n / 12));

        $svg = self::open($largura, $altura);
        $svg .= '<line x1="'.$esq.'" y1="'.$base.'" x2="'.($largura - 4).'" y2="'.$base.'" stroke="#cbd5e1" stroke-width="1"/>';

        foreach ($dados as $i => $d) {
            $x = $esq + $i * $slot + ($slot - $larguraBarra) / 2;
            $h = $d['count'] === 0 ? 1.5 : ($base - $topo) * $d['count'] / $maximo;
            $cor = $d['count'] === 0 ? '#e2e8f0' : self::BRAND;

            $svg .= '<rect x="'.self::n($x).'" y="'.self::n($base - $h).'" width="'.self::n($larguraBarra).'" height="'.self::n($h).'" rx="2" fill="'.$cor.'"/>';

            if ($d['count'] > 0) {
                $svg .= '<text x="'.self::n($x + $larguraBarra / 2).'" y="'.self::n($base - $h - 3).'" font-size="9" text-anchor="middle" fill="#475569">'.$d['count'].'</text>';
            }

            if ($i % $passo === 0) {
                $svg .= '<text x="'.self::n($x + $larguraBarra / 2).'" y="'.($altura - 6).'" font-size="8" text-anchor="middle" fill="'.self::MUTED.'">'.htmlspecialchars($d['label']).'</text>';
            }
        }

        return $svg.'</svg>';
    }

    /**
     * A linha de evolução de um exercício: um ponto por treino. Sem eixos; o número de partida e de chegada vão ao lado.
     *
     * @param  list<float>  $valores
     */
    public static function spark(array $valores, int $largura = 150, int $altura = 38, string $cor = self::BRAND): string
    {
        $n = count($valores);

        if ($n === 0) {
            return '';
        }

        $margem = 4;
        $min = min($valores);
        $max = max($valores);
        $faixa = $max - $min;
        $x = fn (int $i): float => $n === 1 ? $largura / 2 : $margem + $i * ($largura - 2 * $margem) / ($n - 1);
        $y = fn (float $v): float => $faixa == 0.0 ? $altura / 2 : $margem + ($max - $v) / $faixa * ($altura - 2 * $margem);

        $svg = self::open($largura, $altura);
        $svg .= '<line x1="'.$margem.'" y1="'.($altura - 1).'" x2="'.($largura - $margem).'" y2="'.($altura - 1).'" stroke="#e2e8f0" stroke-width="1"/>';

        if ($n > 1) {
            $pontos = [];
            foreach ($valores as $i => $v) {
                $pontos[] = self::n($x($i)).','.self::n($y($v));
            }
            $svg .= '<polyline points="'.implode(' ', $pontos).'" fill="none" stroke="'.$cor.'" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>';
        }

        foreach ($valores as $i => $v) {
            $ultimo = $i === $n - 1;
            $svg .= '<circle cx="'.self::n($x($i)).'" cy="'.self::n($y($v)).'" r="'.($ultimo ? '2.8' : '1.6').'" fill="'.($ultimo ? $cor : '#fff').'" stroke="'.$cor.'" stroke-width="1"/>';
        }

        return $svg.'</svg>';
    }

    /** O SVG como imagem embutida, para o PDF (o gerador não executa HTML inline de SVG, mas lê imagem). */
    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private static function open(int $largura, int $altura): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$largura.'" height="'.$altura.'" viewBox="0 0 '.$largura.' '.$altura.'" font-family="Helvetica, Arial, sans-serif">';
    }

    private static function n(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
