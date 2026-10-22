<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Trava de escrita do cuidador, no model (servidor), não só na tela: esconder o botão não impede uma chamada Livewire direta.
 *
 * - Cuidador de LEITURA não grava, não altera e não apaga nada.
 * - Cuidador de EDIÇÃO cria e altera, mas só apaga o que ele mesmo registrou (o dono apaga qualquer coisa).
 * - Todo registro novo guarda quem o criou (`created_by_user_id` / `changed_by_user_id`): o cuidador não tem membro no perfil, então o
 *   `created_by_member_id` sozinho não diria "alterado por Fulano".
 *
 * Quem não é cuidador passa sem efeito algum (dono, cônjuge e demais seguem as regras de sempre).
 */
class CaregiverWriteGuard
{
    private const ACTOR_COLUMNS = ['created_by_user_id', 'changed_by_user_id'];

    /** @param  class-string<Model>  $modelClass */
    public static function register(string $modelClass): void
    {
        $modelClass::creating(function (Model $model): void {
            self::assertCanWrite($model);
            self::stampActor($model);
        });

        $modelClass::updating(function (Model $model): void {
            self::assertCanWrite($model);
        });

        $modelClass::deleting(function (Model $model): void {
            self::assertCanWrite($model);

            $contexto = app(ProfileContext::class);

            if ($contexto->isCaregiver() && $model->getAttribute('created_by_user_id') !== auth()->id()) {
                throw new AuthorizationException('Você só pode excluir o que você mesmo registrou.');
            }
        });
    }

    private static function assertCanWrite(Model $model): void
    {
        // O nível vale por pessoa: quem tem edição de uma e leitura de outra não grava na segunda.
        if (! app(ProfileContext::class)->canWriteAsCaregiver($model->getAttribute('member_id'))) {
            throw new AuthorizationException('Seu acesso a esta pessoa é só de leitura.');
        }
    }

    private static function stampActor(Model $model): void
    {
        $usuario = auth()->id();

        if ($usuario === null) {
            return;
        }

        foreach (self::ACTOR_COLUMNS as $coluna) {
            if (in_array($coluna, $model->getFillable(), true) && $model->getAttribute($coluna) === null) {
                $model->setAttribute($coluna, $usuario);
            }
        }
    }
}
