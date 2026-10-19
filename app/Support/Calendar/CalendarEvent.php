<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;

/**
 * Um compromisso do calendário, já resolvido para mostrar: quem monta a lista (Saúde, agenda do consultor) traduz o seu model
 * para isto, e o calendário só desenha. Não carrega model: o calendário não precisa saber de onde veio.
 */
final class CalendarEvent
{
    /** Duração mostrada quando o compromisso não diz quanto dura (uma consulta marcada só com a hora). */
    public const DEFAULT_MINUTES = 60;

    public readonly string $color;

    /**
     * @param  string  $key  identifica o compromisso na tela ("consulta:uuid"); é o que volta no clique
     * @param  list<string>  $lines  as linhas de apoio, da mais importante à menos (pessoa, local...)
     * @param  bool  $allDay  vale o dia inteiro (item de cuidado, prazo de tarefa): sem horário na grade
     * @param  bool  $hollow  desenho vazado, para o que não é um horário marcado
     * @param  bool  $muted  esmaecido: o que já passou ou foi concluído
     * @param  string|null  $shortTitle  o título curto das etiquetas apertadas (mês, semana); o título inteiro fica no detalhe
     * @param  list<string>|null  $detailLines  as linhas do detalhe, quando a tela mostra o resto por conta própria; nulo = as mesmas de $lines
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly CarbonImmutable $start,
        public readonly ?CarbonImmutable $end = null,
        public readonly bool $allDay = false,
        string $color = '#64748B',
        public readonly string $tag = '',
        public readonly array $lines = [],
        public readonly bool $hollow = false,
        public readonly bool $muted = false,
        public readonly ?string $shortTitle = null,
        public readonly ?array $detailLines = null,
    ) {
        // A cor vai para o atributo style: nada fora de #RRGGBB passa.
        $this->color = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? strtoupper($color) : '#64748B';
    }

    /** O que cabe numa etiqueta apertada: o título curto, se houver. */
    public function chipTitle(): string
    {
        return $this->shortTitle ?? $this->title;
    }

    /** As linhas que o detalhe do compromisso mostra. */
    public function modalLines(): array
    {
        return $this->detailLines ?? $this->lines;
    }

    public function date(): string
    {
        return $this->start->toDateString();
    }

    /** O fim, ou o início mais a duração padrão; nunca passa da meia-noite do mesmo dia. */
    public function endsAt(): CarbonImmutable
    {
        $fim = $this->end ?? $this->start->addMinutes(self::DEFAULT_MINUTES);

        if ($fim->lessThanOrEqualTo($this->start)) {
            $fim = $this->start->addMinutes(self::DEFAULT_MINUTES);
        }

        return $fim->greaterThan($this->start->endOfDay()) ? $this->start->endOfDay() : $fim;
    }

    /** "14:00" para quem não tem fim conhecido, "14:00 – 15:00" para quem tem; vazio para dia inteiro. */
    public function timeLabel(): string
    {
        if ($this->allDay) {
            return '';
        }

        return $this->end === null
            ? $this->start->format('H:i')
            : $this->start->format('H:i').' – '.$this->endsAt()->format('H:i');
    }
}
