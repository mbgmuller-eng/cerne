<?php

namespace Tests\Unit;

use App\Support\KnownBanks;
use PHPUnit\Framework\TestCase;

class KnownBanksTest extends TestCase
{
    public function test_reconhece_nome_exato_da_lista(): void
    {
        self::assertSame('#EC7000', KnownBanks::colorFor('Itaú'));
    }

    public function test_reconhece_apelido_sem_acento_e_maiusculas(): void
    {
        self::assertSame('#EC7000', KnownBanks::colorFor('itau'));
        self::assertSame('#EC7000', KnownBanks::colorFor('ITAÚ'));
    }

    public function test_reconhece_apelido_mapeado(): void
    {
        self::assertSame(KnownBanks::LIST['Caixa Econômica Federal'], KnownBanks::colorFor('caixa'));
        self::assertSame(KnownBanks::LIST['Banco Inter'], KnownBanks::colorFor('Inter'));
    }

    public function test_banco_desconhecido_devolve_nulo(): void
    {
        self::assertNull(KnownBanks::colorFor('Banco da Esquina Ltda'));
    }

    public function test_lista_de_nomes_vem_ordenada_e_sem_duplicar(): void
    {
        $nomes = KnownBanks::names();
        $ordenados = $nomes;
        sort($ordenados, SORT_FLAG_CASE | SORT_STRING);

        self::assertSame($ordenados, $nomes);
        self::assertSame(count($nomes), count(array_unique($nomes)));
        self::assertContains('Nubank', $nomes);
    }
}
