<?php

namespace App\Support;

use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Models\FinancialProfile;

/**
 * Como o consultor vê o cliente na carteira: o nome do dono do perfil e, quando o perfil é de casal, o do cônjuge
 * junto ("Roberto e Fernanda Albuquerque"). Sobrenome igual aparece uma vez só; diferente, os dois nomes completos
 * ("Roberto Rodrigues e Fernanda Albuquerque").
 *
 * Quem chama precisa ter carregado `owner` e `members.user` do perfil (a carteira lista muitos perfis de uma vez).
 */
final class ClientDisplayName
{
    /**
     * @param  bool  $onlyWithLogin  só junta o cônjuge quando ele tem login próprio. É o que a Carteira usa: o nome
     *                               combinado ali já diz "dois acessos". Nas telas de seguro e investimento o
     *                               cônjuge aparece sempre que existe, tenha ou não login.
     */
    public static function forProfile(FinancialProfile $profile, bool $onlyWithLogin = false): string
    {
        $titular = $profile->owner->name;

        if ($profile->profile_type === ProfileType::Single) {
            return $titular;
        }

        $conjuge = $profile->members->first(fn ($m) => $m->role === MemberRole::Secondary && $m->is_active);

        if ($conjuge === null || ($onlyWithLogin && $conjuge->user === null)) {
            return $titular;
        }

        // O nome da conta (login) é o que a pessoa escreveu; o do membro é o do cadastro do perfil.
        $parceiro = $conjuge->user?->name ?? $conjuge->name;
        $sobrenomeTitular = self::lastName($titular);

        return ($sobrenomeTitular !== '' && $sobrenomeTitular === self::lastName($parceiro))
            ? self::firstName($titular).' e '.$parceiro
            : $titular.' e '.$parceiro;
    }

    private static function lastName(string $nomeCompleto): string
    {
        $partes = explode(' ', trim($nomeCompleto));

        return count($partes) > 1 ? end($partes) : '';
    }

    private static function firstName(string $nomeCompleto): string
    {
        return explode(' ', trim($nomeCompleto))[0];
    }
}
