<?php

namespace App\Support;

use App\Models\HealthAppointment;
use App\Models\HealthCareItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Um evento de agenda (consulta, exame ou item de cuidado) pronto para sair do Cerne de duas formas:
 * um link do Google Agenda e um arquivo .ics (Google, Apple, Outlook).
 *
 * Nada aqui fala com o Google: o dado só sai quando a pessoa toca no link ou baixa o arquivo, o mesmo
 * princípio de MapLinks. As observações cadastradas no Cerne ficam FORA por padrão (são dado de saúde);
 * só entram quando a pessoa pede ($withNotes).
 */
final class CalendarEvent
{
    /** Duração presumida: o cadastro de consulta/exame guarda só o horário de início. */
    private const APPOINTMENT_MINUTES = 60;

    /** O endereço do Google Agenda tem limite de tamanho; o arquivo .ics leva o texto inteiro. */
    private const GOOGLE_NOTES_LIMIT = 900;

    private function __construct(
        private readonly string $uid,
        private readonly string $title,
        private readonly CarbonInterface $start,
        private readonly CarbonInterface $end,
        private readonly bool $allDay,
        private readonly ?string $location,
        private readonly string $description,
        private readonly ?string $notes,
        private readonly CarbonInterface $modifiedAt,
    ) {}

    public static function forAppointment(HealthAppointment $consulta, bool $withNotes = false, bool $showPerson = false): self
    {
        $linhas = [];

        if ($showPerson && $consulta->member) {
            $linhas[] = 'Para: '.$consulta->member->name;
        }

        $profissional = trim((string) $consulta->professional_name);
        $especialidade = trim((string) $consulta->specialty);

        if ($profissional !== '') {
            $linhas[] = 'Profissional: '.$profissional.($especialidade !== '' ? " ({$especialidade})" : '');
        } elseif ($especialidade !== '') {
            $linhas[] = 'Especialidade: '.$especialidade;
        }

        if (filled($consulta->phone)) {
            $linhas[] = 'Telefone: '.trim($consulta->phone);
        }

        $inicio = CarbonImmutable::instance($consulta->scheduled_at);

        return new self(
            uid: "consulta-{$consulta->id}@cerne",
            title: $consulta->title,
            start: $inicio,
            end: $inicio->addMinutes(self::APPOINTMENT_MINUTES),
            allDay: false,
            location: MapLinks::destination($consulta->location, $consulta->address),
            description: implode("\n", $linhas),
            notes: $withNotes && filled($consulta->notes) ? trim($consulta->notes) : null,
            modifiedAt: $consulta->updated_at ?? $inicio,
        );
    }

    /** Item de cuidado: evento de dia inteiro na próxima data prevista. Sem data, não há o que agendar. */
    public static function forCareItem(HealthCareItem $item, bool $withNotes = false, bool $showPerson = false): ?self
    {
        if ($item->next_due_on === null) {
            return null;
        }

        $linhas = [];

        if ($showPerson && $item->member) {
            $linhas[] = 'Para: '.$item->member->name;
        }

        $linhas[] = 'Categoria: '.$item->category->label();
        $linhas[] = 'Frequência: '.$item->frequencyLabel();

        if ($item->last_done_on) {
            $linhas[] = 'Última vez: '.$item->last_done_on->format('d/m/Y');
        }

        $dia = CarbonImmutable::parse($item->next_due_on->toDateString());
        $nome = trim($item->name).(filled($item->device_name) ? ' ('.trim($item->device_name).')' : '');

        return new self(
            uid: "cuidado-{$item->id}@cerne",
            title: 'Trocar ou revisar: '.$nome,
            start: $dia,
            end: $dia->addDay(),
            allDay: true,
            location: null,
            description: implode("\n", $linhas),
            notes: $withNotes && filled($item->notes) ? trim($item->notes) : null,
            modifiedAt: $item->updated_at ?? $dia,
        );
    }

    public function filename(): string
    {
        $base = \Illuminate\Support\Str::slug($this->title) ?: 'evento';

        return \Illuminate\Support\Str::limit($base, 60, '').'.ics';
    }

    public function googleUrl(): string
    {
        $parametros = [
            'action' => 'TEMPLATE',
            'text' => $this->title,
            'dates' => $this->googleDates(),
            'details' => $this->detailsText(self::GOOGLE_NOTES_LIMIT),
        ];

        if ($this->location !== null) {
            $parametros['location'] = $this->location;
        }

        return 'https://calendar.google.com/calendar/render?'.http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
    }

    public function ics(): string
    {
        $linhas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Cerne//Cerne Saude//PT-BR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$this->uid,
            'DTSTAMP:'.$this->utc(CarbonImmutable::now()),
            'LAST-MODIFIED:'.$this->utc($this->modifiedAt),
        ];

        if ($this->allDay) {
            $linhas[] = 'DTSTART;VALUE=DATE:'.$this->start->format('Ymd');
            $linhas[] = 'DTEND;VALUE=DATE:'.$this->end->format('Ymd');
        } else {
            $linhas[] = 'DTSTART:'.$this->utc($this->start);
            $linhas[] = 'DTEND:'.$this->utc($this->end);
        }

        $linhas[] = 'SUMMARY:'.$this->escape($this->title);

        if ($this->location !== null) {
            $linhas[] = 'LOCATION:'.$this->escape($this->location);
        }

        $descricao = $this->detailsText();

        if ($descricao !== '') {
            $linhas[] = 'DESCRIPTION:'.$this->escape($descricao);
        }

        $linhas[] = 'END:VEVENT';
        $linhas[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $linhas))."\r\n";
    }

    /** Descrição do evento: linhas fixas e, só se a pessoa pediu, as observações. */
    private function detailsText(?int $notesLimit = null): string
    {
        $texto = $this->description;

        if ($this->notes !== null) {
            $notas = $notesLimit !== null ? \Illuminate\Support\Str::limit($this->notes, $notesLimit) : $this->notes;
            $texto .= ($texto !== '' ? "\n\n" : '').'Observações: '.$notas;
        }

        return $texto;
    }

    private function googleDates(): string
    {
        if ($this->allDay) {
            return $this->start->format('Ymd').'/'.$this->end->format('Ymd');
        }

        return $this->utc($this->start).'/'.$this->utc($this->end);
    }

    /** Horário absoluto (UTC): dispensa bloco de fuso no arquivo e vale em qualquer agenda. */
    private function utc(CarbonInterface $momento): string
    {
        return CarbonImmutable::instance($momento)->utc()->format('Ymd\THis\Z');
    }

    private function escape(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $texto);
    }

    /** Linhas do .ics têm no máximo 75 bytes; a continuação começa com um espaço. Não corta caractere acentuado ao meio. */
    private function fold(string $linha): string
    {
        if (strlen($linha) <= 75) {
            return $linha;
        }

        $partes = [];
        $inicio = 0;
        $limite = 75;

        while ($inicio < strlen($linha)) {
            $pedaco = mb_strcut($linha, $inicio, $limite, 'UTF-8');
            $partes[] = $pedaco;
            $inicio += strlen($pedaco);
            $limite = 74;
        }

        return implode("\r\n ", $partes);
    }
}
