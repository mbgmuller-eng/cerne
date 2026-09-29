<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum HealthAppointmentKind: string
{
    use HasOptions;

    case Consultation = 'consultation';
    case Exam = 'exam';

    public function label(): string
    {
        return match ($this) {
            self::Consultation => 'Consulta',
            self::Exam => 'Exame',
        };
    }
}
