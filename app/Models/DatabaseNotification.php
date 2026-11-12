<?php

namespace App\Models;

use App\Casts\SensitiveNotificationData;
use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;

/**
 * A notificação do sino, com os textos de `data` cifrados no banco (ver SensitiveNotificationData). O `User` aponta para este model em
 * `notifications()`, então quem grava (canal `database`) e quem lê (sino, cópia dos dados) passam pelo cast sem saber.
 *
 * Não usa `BelongsToProfile`: a notificação é da PESSOA (`notifiable`), não de um perfil, e está em `EXEMPT` do TenancyCoverageTest.
 */
class DatabaseNotification extends BaseDatabaseNotification
{
    protected $casts = [
        'data' => SensitiveNotificationData::class,
        'read_at' => 'datetime',
    ];
}
