<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SharedTaxonomyScope (CLAUDE.md, regra 1): categorias padrão do sistema
 * (profile_id nulo) precisam ser visíveis a todos os perfis, mas uma
 * categoria customizada por um casal — que pode levar o nome de um filho
 * ou de uma situação privada — não pode vazar para outro perfil.
 */
class TaxonomyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_categoria_padrao_do_sistema_e_visivel_a_qualquer_perfil(): void
    {
        $perfilA = FinancialProfile::factory()->create();
        $perfilB = FinancialProfile::factory()->create();
        $padrao = ExpenseCategory::factory()->shared()->create();

        app(ProfileContext::class)->set($perfilA);
        self::assertNotNull(ExpenseCategory::find($padrao->id));

        app(ProfileContext::class)->set($perfilB);
        self::assertNotNull(ExpenseCategory::find($padrao->id));
    }

    public function test_categoria_customizada_so_e_visivel_ao_proprio_perfil(): void
    {
        $perfilA = FinancialProfile::factory()->create();
        $perfilB = FinancialProfile::factory()->create();
        $customA = ExpenseCategory::factory()->custom($perfilA)->create(['name' => 'Terapia da Ana']);

        app(ProfileContext::class)->set($perfilA);
        self::assertTrue(ExpenseCategory::all()->contains($customA));

        app(ProfileContext::class)->set($perfilB);
        self::assertFalse(ExpenseCategory::all()->contains($customA));
        self::assertNull(ExpenseCategory::find($customA->id));
    }

    /**
     * Diferente do ProfileScope comum: sem perfil ativo, as categorias
     * padrão continuam visíveis (são do sistema, não de um perfil), só as
     * customizadas somem.
     */
    public function test_sem_perfil_ativo_ve_apenas_as_categorias_padrao(): void
    {
        $perfilA = FinancialProfile::factory()->create();
        $padrao = ExpenseCategory::factory()->shared()->create();
        $customA = ExpenseCategory::factory()->custom($perfilA)->create();

        app(ProfileContext::class)->clear();

        $visiveis = ExpenseCategory::all();

        self::assertTrue($visiveis->contains($padrao));
        self::assertFalse($visiveis->contains($customA));
    }

    public function test_without_taxonomy_scope_atravessa_o_isolamento_deliberadamente(): void
    {
        $perfilA = FinancialProfile::factory()->create();
        $perfilB = FinancialProfile::factory()->create();
        ExpenseCategory::factory()->custom($perfilA)->create();
        ExpenseCategory::factory()->custom($perfilB)->create();

        app(ProfileContext::class)->set($perfilA);

        self::assertCount(2, ExpenseCategory::withoutTaxonomyScope()->get());
    }
}
