<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * O limite de upload do Livewire (temporary_file_upload) roda ANTES da
 * validação da própria tela — se ficar menor que cerne.ai.max_upload_mb,
 * o arquivo é rejeitado sem chegar nem perto da regra que a tela anuncia.
 */
class DocumentUploadLimitTest extends TestCase
{
    public function test_limite_de_upload_temporario_do_livewire_acompanha_o_limite_do_cerne(): void
    {
        $limiteCerneKb = config('cerne.ai.max_upload_mb') * 1024;
        $regras = config('livewire.temporary_file_upload.rules');

        $regraDeTamanho = collect($regras)->first(fn ($regra) => str_starts_with($regra, 'max:'));

        self::assertNotNull($regraDeTamanho, 'temporary_file_upload.rules precisa definir um limite "max:".');

        $limiteLivewireKb = (int) str_replace('max:', '', $regraDeTamanho);

        self::assertGreaterThanOrEqual($limiteCerneKb, $limiteLivewireKb);
    }
}
