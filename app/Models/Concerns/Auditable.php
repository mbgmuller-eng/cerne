<?php

namespace App\Models\Concerns;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Trilha de auditoria automática.
 *
 * A especificação é explícita: a gravação acontece no servidor, por observer
 * ou trigger, e nunca a partir do frontend. Esta trait resolve isso via
 * eventos do Eloquent, de modo que qualquer caminho que altere o model —
 * tela, job, comando de console, importação de PDF — é registrado.
 */
trait Auditable
{
    /** Campos que nunca entram no log (segredos e ruído). */
    protected array $auditExclude = [
        'password',
        'remember_token',
        'token',
        'created_at',
        'updated_at',
    ];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => $model->writeAuditLog(
            AuditAction::Created,
            null,
            $model->auditableAttributes($model->getAttributes()),
        ));

        static::updated(function (Model $model): void {
            $changes = $model->auditableAttributes($model->getChanges());

            // Alteração que só tocou campos excluídos não vira ruído no log.
            if ($changes === []) {
                return;
            }

            $model->writeAuditLog(
                AuditAction::Updated,
                $model->auditableAttributes(
                    array_intersect_key($model->getOriginal(), $changes)
                ),
                $changes,
            );
        });

        static::deleted(fn (Model $model) => $model->writeAuditLog(
            AuditAction::Deleted,
            $model->auditableAttributes($model->getOriginal()),
            null,
        ));
    }

    protected function writeAuditLog(AuditAction $action, ?array $old, ?array $new): void
    {
        $context = app(ProfileContext::class);
        $profileId = $this->getAttribute('profile_id') ?? $context->profileId();
        $userId = auth()->id();

        // Sem perfil ou sem usuário não há o que auditar de forma útil —
        // é o caso de seeders e migrações de dados.
        if ($profileId === null || $userId === null) {
            return;
        }

        $request = request();

        AuditLog::create([
            'profile_id' => $profileId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $this->getTable(),
            'entity_id' => $this->getKey(),
            'old_value' => $old,
            'new_value' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function auditableAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditExclude));
    }
}
