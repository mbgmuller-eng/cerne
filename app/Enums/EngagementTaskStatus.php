<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EngagementTaskStatus: string
{
    use HasOptions;

    case Todo = 'todo';
    case Doing = 'doing';
    case Waiting = 'waiting';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'A fazer',
            self::Doing => 'Em andamento',
            self::Waiting => 'Aguardando',
            self::Done => 'Concluída',
        };
    }
}
