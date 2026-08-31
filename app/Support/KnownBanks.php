<?php

namespace App\Support;

/**
 * Bancos brasileiros mais comuns, com uma cor próxima da marca oficial —
 * preenche a cor sozinha ao escolher um da lista no cadastro de conta ou
 * cartão (ver AccountsIndex::updatedAccountBankName()). Cores por
 * aproximação de material de marca público; sempre editável depois no
 * seletor de cor da tela. Não reproduz o logo em si — só a cor + iniciais
 * (ver HasBadgeInitials), pra não mexer com marca registrada de terceiro.
 */
class KnownBanks
{
    /** @var array<string, string> nome de exibição => cor hex */
    public const LIST = [
        'Banco do Brasil' => '#FADB14',
        'Itaú' => '#EC7000',
        'Bradesco' => '#CC092F',
        'Santander' => '#EC0000',
        'Caixa Econômica Federal' => '#0033A0',
        'Nubank' => '#8A05BE',
        'Banco Inter' => '#FF7A00',
        'C6 Bank' => '#242424',
        'BTG Pactual' => '#001B48',
        'XP Investimentos' => '#000000',
        'PagBank' => '#00A868',
        'Sicoob' => '#00A651',
        'Sicredi' => '#7AB800',
        'Banco Original' => '#00A65E',
        'Neon' => '#00AAFF',
        'Banco Pan' => '#F58220',
        'Mercado Pago' => '#00B1EA',
        'PicPay' => '#21C25E',
        'Banco Safra' => '#003865',
        'BMG' => '#F26522',
        'Banco BV' => '#FF5500',
        'Banrisul' => '#0066B3',
        'Banco Sofisa' => '#E4032E',
        'Will Bank' => '#7B2FF7',
        'Next' => '#00E09E',
        'Banco Daycoval' => '#003C71',
        'Banco Modal' => '#0B0B0B',
        'Agibank' => '#FDB913',
        'Banco Master' => '#0B1F3A',
    ];

    /** Apelidos comuns que apontam pro nome canônico da lista acima. */
    private const ALIASES = [
        'itau' => 'Itaú',
        'itau unibanco' => 'Itaú',
        'caixa' => 'Caixa Econômica Federal',
        'cef' => 'Caixa Econômica Federal',
        'inter' => 'Banco Inter',
        'pagseguro' => 'PagBank',
        'safra' => 'Banco Safra',
        'votorantim' => 'Banco BV',
        'bv' => 'Banco BV',
        'sofisa' => 'Banco Sofisa',
        'modalmais' => 'Banco Modal',
        'modal mais' => 'Banco Modal',
    ];

    /** Nomes pro <datalist> do formulário, em ordem alfabética. */
    public static function names(): array
    {
        $nomes = array_keys(self::LIST);
        sort($nomes, SORT_FLAG_CASE | SORT_STRING);

        return $nomes;
    }

    /** Cor conhecida pro nome digitado, ou nulo se não é um banco da lista. */
    public static function colorFor(string $bankName): ?string
    {
        $chave = self::normalize($bankName);

        foreach (self::ALIASES as $apelido => $canonico) {
            if ($chave === $apelido) {
                return self::LIST[$canonico];
            }
        }

        foreach (self::LIST as $nome => $cor) {
            if (self::normalize($nome) === $chave) {
                return $cor;
            }
        }

        return null;
    }

    private static function normalize(string $name): string
    {
        $semAcento = strtr(mb_strtolower(trim($name)), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);

        return $semAcento;
    }
}
