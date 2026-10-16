<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Livro dos lembretes já enviados (tabela engagement_reminders, índice único por usuário + assunto + tipo): quem consegue inserir
 * a linha é quem pode enviar o aviso. Assim o agendador disparando duas vezes, ou duas rotinas concorrentes, nunca avisam em
 * dobro. É a proteção por índice único da regra 4 do CLAUDE.md, em vez de um "se já existe" que perde a corrida.
 */
class ReminderLedger
{
    /** @return bool verdadeiro se o lembrete foi reservado agora (e deve ser enviado) */
    public function claim(string $userId, string $subjectType, string $subjectId, string $kind): bool
    {
        return DB::table('engagement_reminders')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'kind' => $kind,
            'sent_on' => today()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
