<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de "entrar como": quem do time acessou a conta de quem, quando e por quê. Só o administrador lê. Não pertence a perfil nenhum
 * e sobrevive à exclusão da conta acessada (nome e e-mail viram "[excluído]", o id e o papel ficam).
 */
#[Fillable([
    'admin_id', 'admin_email', 'target_user_id', 'target_name', 'target_email', 'target_role', 'reason', 'started_at', 'ended_at', 'ip_address', 'user_agent',
])]
class ImpersonationLog extends Model
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
