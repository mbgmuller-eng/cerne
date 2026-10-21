<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Um tipo de erro da plataforma (classe + arquivo + linha) com o contador de ocorrências. Escrito por ErrorRecorder; lido só pelo
 * administrador (tela Erros e relatório diário). Não pertence a perfil nenhum e não guarda dado de cliente (ver a migration).
 */
#[Fillable(['status', 'resolved_at'])]
class ErrorLog extends Model
{
    public const NEW = 'new';

    public const RESOLVED = 'resolved';

    public const IGNORED = 'ignored';

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::NEW);
    }

    /** A última linha da classe: "RuntimeException" em vez de "Symfony\Component\...\RuntimeException". */
    public function shortClass(): string
    {
        return class_basename($this->exception_class);
    }
}
