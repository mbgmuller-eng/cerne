<?php

namespace App\Enums;

/** Em que pé está um exame pedido. Calculado do compromisso e do resultado, nunca gravado. */
enum HealthExamItemStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Done = 'done';
    case Result = 'result';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Falta agendar',
            self::Scheduled => 'Marcado',
            self::Done => 'Feito, falta o resultado',
            self::Result => 'Com resultado',
        };
    }

    public function chip(): string
    {
        return match ($this) {
            self::Pending, self::Done => 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300',
            self::Scheduled => 'bg-saude-200/40 text-saude-800 dark:bg-saude-800/40 dark:text-saude-200',
            self::Result => 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300',
        };
    }
}
