<?php

namespace App\Models\Concerns;

/**
 * Marca um model de finanças como visível ao familiar que acompanha as finanças (só leitura). É a LISTA DE PERMITIDOS dele, como
 * `visibleToCaregiver()` é a da saúde: o que não declara isso devolve zero linhas (ProfileScope), e o que declara ainda passa pela privacidade
 * do casal (MemberPrivacyScope). Model novo nasce fechado.
 */
trait VisibleToFamily
{
    public static function visibleToFamily(): bool
    {
        return true;
    }
}