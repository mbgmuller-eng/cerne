<?php

namespace Tests\Feature;

use App\Models\FinancialProfile;
use App\Models\Insurer;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Insurer segue o padrão de Bank (BelongsToProfileOrShared): lista aprovada
 * vinda da migration + sugestões privadas de cada perfil até um admin
 * aprovar. A apólice continua guardando o nome em texto.
 */
class InsurerTest extends TestCase
{
    use RefreshDatabase;

    private function criarPerfilAtivo(): FinancialProfile
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        app(ProfileContext::class)->set($perfil, $membro);

        return $perfil;
    }

    public function test_reconhece_seguradora_aprovada_por_nome_exato_sem_acento_e_maiuscula(): void
    {
        self::assertSame('#F58220', Insurer::colorFor('SulAmérica'));
        self::assertSame('#F58220', Insurer::colorFor('sulamerica'));
        self::assertSame('#003781', Insurer::colorFor('ALLIANZ'));
    }

    public function test_reconhece_apelidos_comuns(): void
    {
        self::assertSame(Insurer::colorFor('Porto Seguro'), Insurer::colorFor('porto'));
        self::assertSame(Insurer::colorFor('Icatu Seguros'), Insurer::colorFor('Icatu'));
        self::assertSame(Insurer::colorFor('Bradesco Seguros'), Insurer::colorFor('bradesco'));
    }

    public function test_seguradora_desconhecida_devolve_nulo(): void
    {
        self::assertNull(Insurer::colorFor('Seguradora da Esquina Ltda'));
    }

    public function test_digitar_seguradora_nova_cria_sugestao_privada_do_perfil(): void
    {
        $perfil = $this->criarPerfilAtivo();

        $seguradora = Insurer::resolveOrSuggest('Seguros do Vale');

        self::assertSame($perfil->id, $seguradora->profile_id);
        self::assertSame('Seguros do Vale', $seguradora->name);
        self::assertNull($seguradora->color_hex);
    }

    public function test_sugerir_o_mesmo_nome_duas_vezes_nao_duplica(): void
    {
        $this->criarPerfilAtivo();

        $primeira = Insurer::resolveOrSuggest('Seguros do Vale');
        $segunda = Insurer::resolveOrSuggest('seguros do vale');

        self::assertSame($primeira->id, $segunda->id);
        self::assertSame(1, Insurer::query()->where('name', 'Seguros do Vale')->count());
    }

    public function test_nome_conhecido_nao_gera_sugestao(): void
    {
        $this->criarPerfilAtivo();
        $antes = Insurer::withoutTaxonomyScope()->count();

        Insurer::resolveOrSuggest('azos');

        self::assertSame($antes, Insurer::withoutTaxonomyScope()->count());
    }

    public function test_sugestao_de_um_perfil_nao_aparece_pra_outro(): void
    {
        $this->criarPerfilAtivo();
        Insurer::resolveOrSuggest('Seguros do Vale');

        $this->criarPerfilAtivo();

        self::assertNotContains('Seguros do Vale', Insurer::names());
    }

    public function test_aprovar_deixa_a_seguradora_visivel_a_todos_e_apaga_duplicatas_de_outros_perfis(): void
    {
        $this->criarPerfilAtivo();
        $minha = Insurer::resolveOrSuggest('Seguros do Vale');
        $this->criarPerfilAtivo();
        Insurer::resolveOrSuggest('SEGUROS DO VALE');

        $minha->approve('#334455');

        self::assertNull($minha->fresh()->profile_id);
        self::assertSame('#334455', Insurer::colorFor('seguros do vale'));
        self::assertSame(1, Insurer::withoutTaxonomyScope()->whereRaw('LOWER(name) = ?', ['seguros do vale'])->count());
    }

    public function test_aprovar_sem_cor_usa_o_cinza_padrao(): void
    {
        $this->criarPerfilAtivo();
        $seguradora = Insurer::resolveOrSuggest('Seguros do Vale');

        $seguradora->approve();

        self::assertSame('#64748B', $seguradora->fresh()->color_hex);
    }

    public function test_dispensar_tira_da_fila_sem_apagar(): void
    {
        $this->criarPerfilAtivo();
        $seguradora = Insurer::resolveOrSuggest('Seguros do Vale');

        $seguradora->dismiss();

        self::assertSame(0, Insurer::withoutTaxonomyScope()->pending()->count());
        self::assertNotNull(Insurer::withoutTaxonomyScope()->find($seguradora->id));
    }

    public function test_mapa_de_cores_resolve_nome_e_apelido_de_uma_vez(): void
    {
        $mapa = Insurer::colorMap();

        self::assertSame('#003781', Insurer::colorIn($mapa, 'allianz'));
        self::assertSame(Insurer::colorIn($mapa, 'Porto Seguro'), Insurer::colorIn($mapa, 'Porto'));
        self::assertNull(Insurer::colorIn($mapa, 'Seguradora Inexistente'));
    }

    public function test_migration_aprova_as_conhecidas_e_enfileira_os_nomes_ja_usados_em_apolices(): void
    {
        $perfilA = FinancialProfile::factory()->create();
        $perfilB = FinancialProfile::factory()->create();
        InsurancePolicy::factory()->create(['profile_id' => $perfilA->id, 'insurer_name' => 'Allianz']);
        InsurancePolicy::factory()->create(['profile_id' => $perfilA->id, 'insurer_name' => 'porto']);
        InsurancePolicy::factory()->create(['profile_id' => $perfilA->id, 'insurer_name' => 'Seguros do Vale']);
        InsurancePolicy::factory()->create(['profile_id' => $perfilB->id, 'insurer_name' => 'SEGUROS DO VALE']);

        Schema::dropIfExists('insurers');
        (require database_path('migrations/2026_10_08_100000_create_insurers_table.php'))->up();

        $aprovadas = DB::table('insurers')->whereNull('profile_id')->pluck('name');
        self::assertContains('Allianz', $aprovadas);
        self::assertContains('Porto Seguro', $aprovadas);

        $pendentes = DB::table('insurers')->whereNotNull('profile_id')->get();
        self::assertCount(1, $pendentes, 'Só o nome desconhecido vira sugestão, uma vez (Allianz e porto já são conhecidos).');
        self::assertSame('Seguros do Vale', $pendentes->first()->name);
        self::assertSame($perfilA->id, $pendentes->first()->profile_id);
    }
}
