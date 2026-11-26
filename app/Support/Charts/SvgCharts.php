<?php

namespace App\Support\Charts;

use App\Support\Money;

/**
 * Gráficos de análise como SVG puro, sem biblioteca: o mesmo texto serve inline na tela e como imagem (data URI) no PDF.
 *
 * Regras de desenho (protótipo aprovado): uma escala por gráfico, marcas finas, 2px de vão da cor da superfície entre fatias e segmentos,
 * grade recessiva, texto sempre na cor de texto (nunca na da série) e `<title>` em cada marca, que vira a dica ao passar o mouse e o texto para
 * leitor de tela. Tudo que vem do banco (nome de categoria, de instituição) passa por `esc()`: o SVG é impresso sem escape na tela.
 *
 * É geometria, por isso float aqui não fere a regra do dinheiro: os valores chegam como decimal e só a posição na tela é float.
 */
final class SvgCharts
{
    private const FONT = 'Helvetica, Arial, sans-serif';

    /**
     * Rosca com o total no meio. Cada fatia é [rótulo, valor decimal, cor opcional].
     *
     * @param  list<array{0: string, 1: string|float|int, 2?: string}>  $fatias
     */
    public static function rosca(array $fatias, ChartPalette $p, string $centro = '', string $centroSub = '', int $tamanho = 200, string $descricao = ''): string
    {
        $fatias = array_values(array_filter($fatias, fn (array $f) => (float) $f[1] > 0));
        $total = array_sum(array_map(fn (array $f) => (float) $f[1], $fatias));

        $svg = self::abre($tamanho, $tamanho, $descricao ?: 'Rosca');
        if ($total <= 0) {
            return $svg.'</svg>';
        }

        $c = $tamanho / 2;
        $raioExt = $c - 4;
        $raioInt = $raioExt * 0.66;
        $angulo = -M_PI / 2;

        foreach ($fatias as $i => $f) {
            $parte = (float) $f[1] / $total;
            $cor = $f[2] ?? $p->serie($i);
            $dica = self::esc($f[0].': '.Money::format($f[1]).' ('.round($parte * 100).'%)');

            if ($parte >= 0.9999) {
                $meio = ($raioExt + $raioInt) / 2;
                $svg .= '<circle cx="'.self::n($c).'" cy="'.self::n($c).'" r="'.self::n($meio).'" fill="none" stroke="'.$cor.'" stroke-width="'.self::n($raioExt - $raioInt).'"><title>'.$dica.'</title></circle>';
                break;
            }

            $fim = $angulo + $parte * 2 * M_PI;
            $grande = $parte > 0.5 ? 1 : 0;
            $d = sprintf(
                'M %s %s A %s %s 0 %d 1 %s %s L %s %s A %s %s 0 %d 0 %s %s Z',
                self::n($c + $raioExt * cos($angulo)), self::n($c + $raioExt * sin($angulo)), self::n($raioExt), self::n($raioExt), $grande,
                self::n($c + $raioExt * cos($fim)), self::n($c + $raioExt * sin($fim)),
                self::n($c + $raioInt * cos($fim)), self::n($c + $raioInt * sin($fim)), self::n($raioInt), self::n($raioInt), $grande,
                self::n($c + $raioInt * cos($angulo)), self::n($c + $raioInt * sin($angulo)),
            );
            $svg .= '<path d="'.$d.'" fill="'.$cor.'" stroke="'.$p->surface.'" stroke-width="2"><title>'.$dica.'</title></path>';
            $angulo = $fim;
        }

        if ($centro !== '') {
            $svg .= '<text x="'.self::n($c).'" y="'.self::n($c + 2).'" text-anchor="middle" font-size="15" font-weight="bold" fill="'.$p->ink.'">'.self::esc($centro).'</text>';
        }
        if ($centroSub !== '') {
            $svg .= '<text x="'.self::n($c).'" y="'.self::n($c + 18).'" text-anchor="middle" font-size="10.5" fill="'.$p->muted.'">'.self::esc($centroSub).'</text>';
        }

        return $svg.'</svg>';
    }

    /**
     * Colunas empilhadas (uma por rótulo). Cada série é [nome, valores decimais (um por rótulo), cor].
     *
     * @param  list<string>  $rotulos
     * @param  list<array{0: string, 1: list<string|float|int>, 2: string}>  $series
     */
    public static function colunasEmpilhadas(array $rotulos, array $series, ChartPalette $p, int $largura = 640, int $altura = 260, string $descricao = '', ?callable $formatar = null): string
    {
        $n = count($rotulos);
        $svg = self::abre($largura, $altura, $descricao ?: 'Colunas empilhadas');
        if ($n === 0) {
            return $svg.'</svg>';
        }

        $totais = [];
        foreach ($rotulos as $i => $_) {
            $totais[$i] = array_sum(array_map(fn (array $s) => (float) ($s[1][$i] ?? 0), $series));
        }
        [$teto, $passo] = self::escala(max($totais) ?: 1);

        $esq = 54;
        $topo = 10;
        $base = $altura - 26;
        $svg .= self::grade($p, $largura, $esq, $topo, $base, $teto, $passo, $formatar);

        $slot = ($largura - $esq - 8) / $n;
        $larg = max(8, min(44, $slot * 0.62));

        foreach ($rotulos as $i => $rotulo) {
            $x = $esq + $i * $slot + ($slot - $larg) / 2;
            $y = $base;

            foreach ($series as $s) {
                $v = (float) ($s[1][$i] ?? 0);
                if ($v <= 0) {
                    continue;
                }
                $h = ($base - $topo) * $v / $teto;
                $y -= $h;
                $svg .= '<rect x="'.self::n($x).'" y="'.self::n($y).'" width="'.self::n($larg).'" height="'.self::n($h).'" fill="'.$s[2].'" stroke="'.$p->surface.'" stroke-width="1.5"><title>'.self::esc($rotulo.' · '.$s[0].': '.($formatar !== null ? $formatar((float) $s[1][$i]) : Money::format($s[1][$i]))).'</title></rect>';
            }

            $svg .= '<text x="'.self::n($x + $larg / 2).'" y="'.($altura - 8).'" text-anchor="middle" font-size="10.5" fill="'.$p->muted.'">'.self::esc($rotulo).'</text>';
        }

        return $svg.'</svg>';
    }

    /**
     * Linhas ao longo de uma sequência (dias, meses). Valor nulo interrompe a linha (o resto do mês que ainda não aconteceu). Cada série é
     * [nome, valores (nulo permitido), cor]; o último ponto de cada série leva um marcador.
     *
     * @param  list<string>  $rotulos
     * @param  list<array{0: string, 1: list<string|float|int|null>, 2: string}>  $series
     */
    public static function linhas(array $rotulos, array $series, ChartPalette $p, int $largura = 640, int $altura = 230, string $descricao = ''): string
    {
        $n = count($rotulos);
        $svg = self::abre($largura, $altura, $descricao ?: 'Linhas');
        if ($n < 2) {
            return $svg.'</svg>';
        }

        $maximo = 1.0;
        foreach ($series as $s) {
            foreach ($s[1] as $v) {
                $maximo = max($maximo, (float) ($v ?? 0));
            }
        }
        [$teto, $passo] = self::escala($maximo);

        $esq = 54;
        $topo = 10;
        $base = $altura - 26;
        $svg .= self::grade($p, $largura, $esq, $topo, $base, $teto, $passo);

        $dx = ($largura - $esq - 12) / ($n - 1);
        $x = fn (int $i): float => $esq + $i * $dx;
        $y = fn (float $v): float => $base - ($base - $topo) * $v / $teto;

        $passoX = max(1, (int) ceil($n / 8));
        foreach ($rotulos as $i => $r) {
            if ($i % $passoX === 0 || $i === $n - 1) {
                $svg .= '<text x="'.self::n($x($i)).'" y="'.($altura - 8).'" text-anchor="middle" font-size="10.5" fill="'.$p->muted.'">'.self::esc($r).'</text>';
            }
        }

        foreach ($series as $s) {
            $d = '';
            $ultimo = null;
            $abre = true;
            foreach ($s[1] as $i => $v) {
                if ($v === null) {
                    $abre = true;

                    continue;
                }
                $d .= ($abre ? ' M ' : ' L ').self::n($x($i)).' '.self::n($y((float) $v));
                $abre = false;
                $ultimo = $i;
            }
            if ($d !== '') {
                $svg .= '<path d="'.trim($d).'" fill="none" stroke="'.$s[2].'" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
            }
            if ($ultimo !== null) {
                $svg .= '<circle cx="'.self::n($x($ultimo)).'" cy="'.self::n($y((float) $s[1][$ultimo])).'" r="4.5" fill="'.$s[2].'" stroke="'.$p->surface.'" stroke-width="2"/>';
            }
        }

        // Faixas de passagem do mouse: uma por posição, com todos os valores do dia na mesma dica.
        foreach ($rotulos as $i => $r) {
            $linhas = [];
            foreach ($series as $s) {
                if (($s[1][$i] ?? null) !== null) {
                    $linhas[] = $s[0].': '.Money::format($s[1][$i]);
                }
            }
            if ($linhas === []) {
                continue;
            }
            $svg .= '<rect x="'.self::n($x($i) - $dx / 2).'" y="'.$topo.'" width="'.self::n($dx).'" height="'.($base - $topo).'" fill="none" pointer-events="all"><title>'.self::esc($r.' · '.implode(' · ', $linhas)).'</title></rect>';
        }

        return $svg.'</svg>';
    }

    /**
     * Barras horizontais, uma por item: [rótulo, valor (para o tamanho), texto ao lado da barra, dica]. A primeira barra recebe a cor de
     * destaque quando `$destacarPrimeira`; as outras ficam em cinza (a história é a concentração, não a cor de cada uma).
     *
     * @param  list<array{0: string, 1: float|int|string, 2: string, 3?: string}>  $itens
     */
    public static function barrasHorizontais(array $itens, ChartPalette $p, bool $destacarPrimeira = true, int $largura = 640, string $descricao = ''): string
    {
        $linha = 30;
        $altura = max(40, count($itens) * $linha + 16);
        $svg = self::abre($largura, $altura, $descricao ?: 'Barras horizontais');
        $maximo = max(1.0, ...array_map(fn (array $i) => (float) $i[1], $itens ?: [[0, 0]]));

        $esq = 150;
        $dir = 70;
        $utilizavel = $largura - $esq - $dir;

        foreach ($itens as $k => $it) {
            $y = 8 + $k * $linha;
            $w = max(2, $utilizavel * (float) $it[1] / $maximo);
            $cor = ($destacarPrimeira && $k === 0) ? $p->serie(0) : $p->gray;

            $svg .= '<text x="'.($esq - 10).'" y="'.self::n($y + 14).'" text-anchor="end" font-size="11.5" fill="'.$p->ink.'">'.self::esc(self::corta($it[0], 24)).'</text>';
            // Retângulo com canto arredondado (rx) e não um caminho com curvas: o conversor de SVG do PDF não lê esse caminho.
            $svg .= '<rect x="'.$esq.'" y="'.$y.'" width="'.self::n($w).'" height="18" rx="3" fill="'.$cor.'"><title>'.self::esc($it[3] ?? ($it[0].': '.$it[2])).'</title></rect>';
            $svg .= '<text x="'.self::n($esq + $w + 8).'" y="'.self::n($y + 14).'" font-size="11.5" fill="'.$p->ink.'">'.self::esc($it[2]).'</text>';
        }
        $svg .= '<line x1="'.$esq.'" y1="4" x2="'.$esq.'" y2="'.($altura - 4).'" stroke="'.$p->axis.'" stroke-width="1"/>';

        return $svg.'</svg>';
    }

    /**
     * Uma barra única de 0 a 100 dividida em partes: [rótulo, percentual, cor]. Partes minúsculas continuam visíveis na dica e na legenda.
     *
     * @param  list<array{0: string, 1: float|int|string, 2: string}>  $partes
     */
    public static function barraUnica(array $partes, ChartPalette $p, int $largura = 640, string $descricao = ''): string
    {
        $altura = 70;
        $svg = self::abre($largura, $altura, $descricao ?: 'Barra única');
        $esq = 6;
        $util = $largura - $esq * 2;
        $x = (float) $esq;

        foreach ($partes as $parte) {
            $pct = max(0.0, (float) $parte[1]);
            $w = $util * $pct / 100;
            if ($w <= 0) {
                continue;
            }
            $svg .= '<rect x="'.self::n($x).'" y="8" width="'.self::n($w).'" height="30" fill="'.$parte[2].'" stroke="'.$p->surface.'" stroke-width="1.5"><title>'.self::esc($parte[0].': R$ '.number_format($pct, 2, ',', '.').' de cada R$ 100').'</title></rect>';
            $x += $w;
        }

        foreach ([0, 25, 50, 75, 100] as $marca) {
            $mx = $esq + $util * $marca / 100;
            $ancora = $marca === 0 ? 'start' : ($marca === 100 ? 'end' : 'middle');
            $svg .= '<text x="'.self::n($mx).'" y="58" text-anchor="'.$ancora.'" font-size="10.5" fill="'.$p->muted.'">R$ '.$marca.'</text>';
        }

        return $svg.'</svg>';
    }

    /** O SVG como imagem embutida (o PDF não lê SVG inline). */
    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** @return array{0: float, 1: float} teto e passo "redondos" para o eixo, com 4 divisões */
    private static function escala(float $maximo): array
    {
        $bruto = $maximo / 4;
        $mag = 10 ** floor(log10(max($bruto, 1)));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $bruto) {
                $passo = $m * $mag;
                break;
            }
        }

        return [$passo * 4, $passo];
    }

    private static function grade(ChartPalette $p, int $largura, int $esq, int $topo, int $base, float $teto, float $passo, ?callable $formatar = null): string
    {
        $svg = '';
        for ($v = 0.0; $v <= $teto + 0.001; $v += $passo) {
            $y = $base - ($base - $topo) * $v / $teto;
            $svg .= '<line x1="'.$esq.'" y1="'.self::n($y).'" x2="'.($largura - 4).'" y2="'.self::n($y).'" stroke="'.($v == 0 ? $p->axis : $p->grid).'" stroke-width="1"/>';
            $svg .= '<text x="'.($esq - 8).'" y="'.self::n($y + 3.5).'" text-anchor="end" font-size="10.5" fill="'.$p->muted.'">'.self::esc($formatar !== null ? $formatar($v) : self::rotuloEixo($v)).'</text>';
        }

        return $svg;
    }

    /** "R$ 850", "R$ 4,5 mil", "R$ 1,2 mi": o eixo precisa caber em poucos caracteres. */
    public static function rotuloEixo(float $v): string
    {
        if ($v >= 1_000_000) {
            return 'R$ '.rtrim(rtrim(number_format($v / 1_000_000, 1, ',', ''), '0'), ',').' mi';
        }
        if ($v >= 1000) {
            return 'R$ '.rtrim(rtrim(number_format($v / 1000, 1, ',', ''), '0'), ',').' mil';
        }

        return 'R$ '.number_format($v, 0, ',', '.');
    }

    private static function abre(int $largura, int $altura, string $descricao): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$largura.'" height="'.$altura.'" viewBox="0 0 '.$largura.' '.$altura.'" font-family="'.self::FONT.'" role="img" aria-label="'.self::esc($descricao).'">';
    }

    private static function corta(string $texto, int $max): string
    {
        return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 1).'…' : $texto;
    }

    private static function n(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private static function esc(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
