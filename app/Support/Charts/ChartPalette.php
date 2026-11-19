<?php

namespace App\Support\Charts;

/**
 * Cores de um gráfico. O MESMO desenho sai em dois destinos: na tela (cores como variáveis CSS, que viram o tema claro ou escuro) e no PDF
 * (hexadecimais fixos, porque o PDF não resolve variável). A paleta categórica é a ordem fixa do protótipo aprovado: a cor segue a categoria, e a
 * nona nunca existe (o que passa de oito vira "Outras", em cinza).
 */
final class ChartPalette
{
    /** @param  list<string>  $series */
    public function __construct(
        public readonly array $series,
        public readonly string $gray,
        public readonly string $grid,
        public readonly string $axis,
        public readonly string $ink,
        public readonly string $muted,
        public readonly string $surface,
    ) {}

    /** Hexadecimais do tema claro: o que o PDF usa. */
    public static function pdf(): self
    {
        return new self(
            ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
            '#b4b2a9', '#e1e0d9', '#c3c2b7', '#52514e', '#898781', '#ffffff',
        );
    }

    /** Variáveis CSS (definidas em resources/css/app.css, com o tema escuro): o que a tela usa. */
    public static function screen(): self
    {
        $p = self::pdf();

        return new self(
            array_map(fn (string $hex, int $i) => 'var(--chart-'.($i + 1).', '.$hex.')', $p->series, array_keys($p->series)),
            'var(--chart-gray, '.$p->gray.')', 'var(--chart-grid, '.$p->grid.')', 'var(--chart-axis, '.$p->axis.')',
            'var(--chart-ink, '.$p->ink.')', 'var(--chart-muted, '.$p->muted.')', 'var(--chart-surface, '.$p->surface.')',
        );
    }

    /** A cor da posição `$i`; do oitavo em diante (nunca deveria chegar) cai no cinza. */
    public function serie(int $i): string
    {
        return $this->series[$i] ?? $this->gray;
    }
}
