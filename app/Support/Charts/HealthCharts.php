<?php

namespace App\Support\Charts;

/**
 * Os dois gráficos do programa de atividades: sessões por semana (colunas) e dor média da semana (linha de 0 a 10). Escalas diferentes, então são
 * dois gráficos, nunca um com dois eixos. SVG próprio, como os de SvgCharts, mas com números simples (aqueles escrevem tudo como dinheiro).
 * Cores por variável CSS (tema claro e escuro). Os rótulos são datas e números calculados aqui; ainda assim passam por escape.
 */
final class HealthCharts
{
    private const W = 320;

    private const H = 150;

    private const LEFT = 26;

    private const BOTTOM = 22;

    private const TOP = 16;

    /** @param  list<array{label: string, value: int}>  $pontos */
    public static function sessions(array $pontos, string $titulo): string
    {
        $maximo = max(4, ...array_map(fn (array $p) => $p['value'], $pontos ?: [['label' => '', 'value' => 0]]));
        $maximo += $maximo % 2;
        $svg = self::open($titulo).self::grid($maximo);
        $passo = self::step(count($pontos));
        $largura = min(28, $passo * 0.6);

        foreach ($pontos as $i => $p) {
            $x = self::LEFT + $passo * $i + $passo / 2;
            $y = self::y($p['value'], $maximo);

            if ($p['value'] > 0) {
                $svg .= '<rect x="'.round($x - $largura / 2, 1).'" y="'.round($y, 1).'" width="'.round($largura, 1).'" height="'.round(self::H - self::BOTTOM - $y, 1).'" rx="3" fill="var(--chart-7, #6250d6)"><title>'.self::esc('Semana de '.$p['label'].': '.$p['value']).'</title></rect>';
            }

            $svg .= self::text($x, $y - 4, (string) $p['value']).self::text($x, self::H - 6, $p['label']);
        }

        return $svg.'</svg>';
    }

    /** @param  list<array{label: string, value: ?float}>  $pontos  semana sem nota interrompe a linha */
    public static function pain(array $pontos, string $titulo): string
    {
        $svg = self::open($titulo).self::grid(10);
        $passo = self::step(count($pontos));
        $trecho = [];
        $linhas = '';
        $marcas = '';

        foreach ($pontos as $i => $p) {
            $x = self::LEFT + $passo * $i + $passo / 2;
            $marcas .= self::text($x, self::H - 6, $p['label']);

            if ($p['value'] === null) {
                $linhas .= self::polyline($trecho);
                $trecho = [];

                continue;
            }

            $y = self::y($p['value'], 10);
            $trecho[] = round($x, 1).','.round($y, 1);
            $valor = str_replace('.', ',', (string) $p['value']);
            $marcas .= '<circle cx="'.round($x, 1).'" cy="'.round($y, 1).'" r="4.5" fill="var(--chart-2, #eb6834)"><title>'.self::esc('Semana de '.$p['label'].': dor média '.$valor).'</title></circle>'.self::text($x, $y - 9, $valor);
        }

        return $svg.$linhas.self::polyline($trecho).$marcas.'</svg>';
    }

    private static function open(string $titulo): string
    {
        return '<svg viewBox="0 0 '.self::W.' '.self::H.'" width="100%" role="img" aria-label="'.self::esc($titulo).'" xmlns="http://www.w3.org/2000/svg" font-size="11">';
    }

    private static function grid(int $maximo): string
    {
        $svg = '';

        foreach ([0, intdiv($maximo, 2), $maximo] as $v) {
            $y = round(self::y($v, $maximo), 1);
            $svg .= '<line x1="'.self::LEFT.'" x2="'.(self::W - 4).'" y1="'.$y.'" y2="'.$y.'" stroke="var(--chart-grid, #e1e0d9)"/>'
                .'<text x="'.(self::LEFT - 6).'" y="'.($y + 4).'" text-anchor="end" fill="var(--chart-muted, #898781)">'.$v.'</text>';
        }

        return $svg;
    }

    private static function step(int $quantos): float
    {
        return (self::W - self::LEFT - 8) / max(1, $quantos);
    }

    private static function y(float $valor, int $maximo): float
    {
        return self::TOP + (self::H - self::TOP - self::BOTTOM) * (1 - $valor / $maximo);
    }

    /** @param  list<string>  $pontos */
    private static function polyline(array $pontos): string
    {
        return count($pontos) > 1 ? '<polyline points="'.implode(' ', $pontos).'" fill="none" stroke="var(--chart-2, #eb6834)" stroke-width="2"/>' : '';
    }

    private static function text(float $x, float $y, string $texto): string
    {
        return '<text x="'.round($x, 1).'" y="'.round($y, 1).'" text-anchor="middle" fill="var(--chart-muted, #898781)">'.self::esc($texto).'</text>';
    }

    private static function esc(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
