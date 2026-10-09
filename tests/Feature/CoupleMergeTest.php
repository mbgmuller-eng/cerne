<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Models\BankAccount;
use App\Models\ConsultantClient;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\CoupleMergeService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Unificação de dois clientes com perfis individuais num perfil de casal: o perfil da principal vira o do
 * casal, o membro da cônjuge é movido para ele (sem recriar, para todo vínculo por member_id continuar valendo)
 * e o perfil antigo, já vazio, some.
 */
class CoupleMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private User $principal;

    private User $conjuge;

    private FinancialProfile $perfilPrincipal;

    private FinancialProfile $perfilConjuge;

    private ProfileMember $membroPrincipal;

    private ProfileMember $membroConjuge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultor = User::factory()->consultant()->create();

        [$this->principal, $this->perfilPrincipal, $this->membroPrincipal] = $this->cliente('Rodrigo Burgos');
        [$this->conjuge, $this->perfilConjuge, $this->membroConjuge] = $this->cliente('Mariana Tanaka');
    }

    /** @return array{0: User, 1: FinancialProfile, 2: ProfileMember} */
    private function cliente(string $nome, bool $comVinculo = true): array
    {
        $usuario = User::factory()->create(['name' => $nome]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id, 'profile_name' => $nome, 'profile_type' => ProfileType::Single]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'name' => $nome, 'role' => MemberRole::Primary]);

        if ($comVinculo) {
            ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $usuario->id, 'status' => ConsultantClientStatus::Active]);
        }

        return [$usuario, $perfil, $membro];
    }

    private function servico(): CoupleMergeService
    {
        return app(CoupleMergeService::class);
    }

    private function unificar(): array
    {
        return $this->servico()->merge($this->principal, $this->conjuge);
    }

    // ---- o que acontece

    public function test_o_perfil_da_principal_vira_o_casal_e_o_membro_da_conjuge_e_movido_com_o_mesmo_id(): void
    {
        $this->unificar();

        $perfil = $this->perfilPrincipal->fresh();
        self::assertSame(ProfileType::Couple, $perfil->profile_type);
        self::assertSame($this->principal->id, $perfil->owner_user_id);

        $membros = ProfileMember::query()->where('profile_id', $perfil->id)->get()->keyBy('id');
        self::assertCount(2, $membros);
        self::assertSame(MemberRole::Primary, $membros[$this->membroPrincipal->id]->role);
        self::assertSame(MemberRole::Secondary, $membros[$this->membroConjuge->id]->role, 'o MESMO registro de membro, agora cônjuge');
        self::assertSame($this->conjuge->id, $membros[$this->membroConjuge->id]->user_id);
    }

    public function test_o_dado_da_conjuge_vai_junto_sem_mudar_de_dono(): void
    {
        $apolice = InsurancePolicy::factory()->life()->create(['profile_id' => $this->perfilConjuge->id, 'member_id' => $this->membroConjuge->id]);
        $conta = BankAccount::factory()->create(['profile_id' => $this->perfilConjuge->id, 'member_id' => $this->membroConjuge->id]);
        $despesa = ExpenseRecord::factory()->create(['profile_id' => $this->perfilConjuge->id, 'member_id' => $this->membroConjuge->id, 'is_private' => true]);
        $daPrincipal = InsurancePolicy::factory()->create(['profile_id' => $this->perfilPrincipal->id, 'member_id' => $this->membroPrincipal->id]);

        $resumo = $this->unificar();

        foreach ([$apolice, $conta, $despesa] as $registro) {
            $registro->refresh();
            self::assertSame($this->perfilPrincipal->id, $registro->profile_id);
            self::assertSame($this->membroConjuge->id, $registro->member_id, 'continua sendo dela');
        }
        self::assertSame($this->perfilPrincipal->id, $daPrincipal->fresh()->profile_id);
        self::assertSame(1, $resumo['linhas']['insurance_policies']);
        self::assertTrue($despesa->fresh()->is_private, 'a privacidade por lançamento continua valendo');
    }

    public function test_o_perfil_antigo_some_e_nao_leva_nada_junto_na_exclusao_em_cascata(): void
    {
        InsurancePolicy::factory()->create(['profile_id' => $this->perfilConjuge->id, 'member_id' => $this->membroConjuge->id]);
        InsurancePolicy::factory()->create(['profile_id' => $this->perfilPrincipal->id, 'member_id' => $this->membroPrincipal->id]);

        $this->unificar();

        self::assertNull(FinancialProfile::query()->find($this->perfilConjuge->id));
        self::assertSame(2, InsurancePolicy::withoutGlobalScopes()->where('profile_id', $this->perfilPrincipal->id)->count(), 'nada se perdeu');
        self::assertNotNull(ProfileMember::query()->find($this->membroConjuge->id), 'o membro não foi apagado em cascata');
    }

    public function test_o_login_da_conjuge_passa_a_abrir_o_perfil_do_casal(): void
    {
        $this->unificar();

        $conjuge = $this->conjuge->fresh();
        self::assertSame(0, $conjuge->ownedProfiles()->count());
        self::assertSame($this->perfilPrincipal->id, $conjuge->memberships()->where('is_active', true)->first()->profile_id);
        self::assertTrue($conjuge->can('view', $this->perfilPrincipal->fresh()));

        // O consultor continua enxergando o casal pelo vínculo da principal.
        self::assertTrue($this->consultor->can('view', $this->perfilPrincipal->fresh()));
    }

    public function test_o_vinculo_da_conjuge_sai_da_carteira_e_o_da_principal_fica(): void
    {
        $this->unificar();

        self::assertSame(0, ConsultantClient::query()->where('client_id', $this->conjuge->id)->count());
        self::assertSame(1, ConsultantClient::query()->where('client_id', $this->principal->id)->where('status', ConsultantClientStatus::Active)->count());
    }

    public function test_regras_de_categorizacao_repetidas_ficam_com_a_da_principal(): void
    {
        $categoria = ExpenseCategory::factory()->shared()->create();
        $sub = \App\Models\ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);
        $regra = fn (FinancialProfile $p, string $padrao) => ExpenseCategorizationRule::factory()->create([
            'profile_id' => $p->id, 'pattern' => $padrao, 'category_id' => $categoria->id, 'subcategory_id' => $sub->id,
        ]);
        $regra($this->perfilPrincipal, 'mercado');
        $regra($this->perfilConjuge, 'mercado');
        $propria = $regra($this->perfilConjuge, 'farmacia');

        $resumo = $this->unificar();

        self::assertSame(['expense_categorization_rules' => 1], $resumo['regrasDuplicadas']);
        $padroes = ExpenseCategorizationRule::withoutGlobalScopes()->where('profile_id', $this->perfilPrincipal->id)->pluck('pattern')->sort()->values()->all();
        self::assertSame(['farmacia', 'mercado'], $padroes);
        self::assertSame($this->perfilPrincipal->id, $propria->fresh()->profile_id);
    }

    public function test_categoria_propria_sem_conflito_acompanha(): void
    {
        $propria = ExpenseCategory::factory()->custom($this->perfilConjuge)->create(['name' => 'Pets']);

        $this->unificar();

        self::assertSame($this->perfilPrincipal->id, $propria->fresh()->profile_id);
    }

    public function test_convites_de_conjuge_pendentes_do_perfil_antigo_sao_descartados(): void
    {
        DB::table('partner_invites')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'profile_id' => $this->perfilConjuge->id, 'invited_by_user_id' => $this->conjuge->id,
            'partner_name' => 'X', 'partner_email' => 'x@example.com', 'token' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $resumo = $this->unificar();

        self::assertSame(['partner_invites' => 1], $resumo['descartadas']);
        self::assertSame(0, DB::table('partner_invites')->count());
    }

    public function test_a_copia_de_seguranca_guarda_o_que_serve_para_desfazer(): void
    {
        $apolice = InsurancePolicy::factory()->create(['profile_id' => $this->perfilConjuge->id, 'member_id' => $this->membroConjuge->id]);

        $copia = $this->unificar()['copia'];

        self::assertSame($this->perfilConjuge->id, $copia['perfil_antigo']['id']);
        self::assertSame($this->membroConjuge->id, $copia['membro_movido']['id']);
        self::assertSame($this->perfilConjuge->id, $copia['membro_movido']['profile_id'], 'a cópia foi tirada ANTES de mover');
        self::assertSame([$apolice->id], $copia['ids_movidos']['insurance_policies']);
        self::assertCount(1, $copia['vinculos_removidos']);
        self::assertSame($this->perfilPrincipal->id, $copia['perfil_novo']);
    }

    // ---- simulação

    public function test_plan_nao_grava_nada(): void
    {
        InsurancePolicy::factory()->create(['profile_id' => $this->perfilConjuge->id, 'member_id' => $this->membroConjuge->id]);

        $plano = $this->servico()->plan($this->principal, $this->conjuge);

        self::assertSame(['insurance_policies' => 1], $plano['linhas']);
        self::assertSame(ProfileType::Single, $this->perfilPrincipal->fresh()->profile_type);
        self::assertNotNull(FinancialProfile::query()->find($this->perfilConjuge->id));
        self::assertSame(1, ConsultantClient::query()->where('client_id', $this->conjuge->id)->count());
        self::assertSame($this->perfilConjuge->id, $this->membroConjuge->fresh()->profile_id);
    }

    // ---- recusas (nada pode ser alterado)

    private function recusa(string $trecho): void
    {
        try {
            $this->unificar();
            self::fail('deveria recusar');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($trecho, $e->getMessage());
        }

        // Nada mudou: os dois perfis, o membro e os vínculos continuam como estavam.
        self::assertNotNull(FinancialProfile::query()->find($this->perfilConjuge->id));
        self::assertSame($this->perfilConjuge->id, $this->membroConjuge->fresh()->profile_id);
        self::assertSame(ProfileType::Single, $this->perfilPrincipal->fresh()->profile_type);
    }

    public function test_recusa_a_mesma_pessoa(): void
    {
        $this->expectException(RuntimeException::class);

        $this->servico()->plan($this->principal, $this->principal);
    }

    public function test_recusa_quando_um_dos_perfis_ja_e_de_casal(): void
    {
        $this->perfilConjuge->update(['profile_type' => ProfileType::Couple]);

        $this->recusa('já é de casal');
    }

    public function test_recusa_quando_a_principal_ja_tem_outro_membro(): void
    {
        ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfilPrincipal->id, 'user_id' => null]);

        $this->recusa('já tem outro membro');
    }

    public function test_recusa_quando_a_conjuge_tem_assinatura_propria(): void
    {
        DB::table('subscriptions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->conjuge->id, 'kind' => 'individual', 'bundle' => 'financas',
            'billing_type' => 'pix', 'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->recusa('assinatura própria');
    }

    public function test_recusa_quando_a_conjuge_e_cliente_de_um_consultor_que_nao_acompanha_a_principal(): void
    {
        $outro = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $outro->id, 'client_id' => $this->conjuge->id, 'status' => ConsultantClientStatus::Active]);

        $this->recusa('perder o acesso');
    }

    public function test_recusa_quando_o_vinculo_da_principal_nao_esta_ativo(): void
    {
        ConsultantClient::query()->where('client_id', $this->principal->id)->update(['status' => ConsultantClientStatus::Inactive]);

        $this->recusa('perder o acesso');
    }

    public function test_recusa_categoria_propria_com_o_mesmo_nome_nos_dois_perfis(): void
    {
        ExpenseCategory::factory()->custom($this->perfilPrincipal)->create(['name' => 'Pets']);
        ExpenseCategory::factory()->custom($this->perfilConjuge)->create(['name' => 'pets ']);

        $this->recusa('mesmo nome');
    }

    public function test_recusa_reserva_do_casal_repetida(): void
    {
        foreach ([$this->perfilPrincipal, $this->perfilConjuge] as $perfil) {
            DB::table('financial_reserves')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'profile_id' => $perfil->id, 'member_id' => null, 'member_key' => 'casal',
                'reserve_type' => 'paz', 'target_amount' => '1000.00', 'current_amount' => '0.00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->recusa('mesma reserva');
    }

    public function test_recusa_quando_a_conjuge_participa_de_outro_perfil(): void
    {
        $outroPerfil = FinancialProfile::factory()->create();
        ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $this->conjuge->id]);

        $this->recusa('outro perfil');
    }

    public function test_conta_que_nao_e_de_cliente_e_recusada(): void
    {
        $this->expectException(RuntimeException::class);

        $this->servico()->plan($this->principal, $this->consultor);
    }

    // ---- o comando

    public function test_comando_sem_apply_so_simula(): void
    {
        $this->artisan('cerne:merge-couple', ['principal' => $this->principal->email, 'conjuge' => $this->conjuge->email])
            ->expectsOutputToContain('Simulação: nada foi gravado')
            ->assertSuccessful();

        self::assertNotNull(FinancialProfile::query()->find($this->perfilConjuge->id));
    }

    public function test_comando_com_apply_exige_o_arquivo_de_copia(): void
    {
        $this->artisan('cerne:merge-couple', ['principal' => $this->principal->email, 'conjuge' => $this->conjuge->email, '--apply' => true])
            ->assertFailed();

        self::assertNotNull(FinancialProfile::query()->find($this->perfilConjuge->id));
    }

    public function test_comando_com_apply_unifica_e_grava_a_copia(): void
    {
        $arquivo = sys_get_temp_dir().'/cerne_merge_'.uniqid().'.json';

        $this->artisan('cerne:merge-couple', ['principal' => $this->principal->email, 'conjuge' => $this->conjuge->email, '--apply' => true, '--backup' => $arquivo])
            ->expectsOutputToContain('Unificado')
            ->assertSuccessful();

        self::assertNull(FinancialProfile::query()->find($this->perfilConjuge->id));
        $copia = json_decode(file_get_contents($arquivo), true);
        self::assertSame($this->membroConjuge->id, $copia['membro_movido']['id']);
        @unlink($arquivo);
    }

    public function test_comando_nao_grava_nada_se_a_copia_nao_puder_ser_escrita(): void
    {
        $this->artisan('cerne:merge-couple', ['principal' => $this->principal->email, 'conjuge' => $this->conjuge->email, '--apply' => true, '--backup' => '/pasta/que/nao/existe/copia.json'])
            ->assertFailed();

        self::assertNotNull(FinancialProfile::query()->find($this->perfilConjuge->id));
        self::assertSame(ProfileType::Single, $this->perfilPrincipal->fresh()->profile_type);
    }

    public function test_comando_com_email_desconhecido_falha_sem_gravar(): void
    {
        $this->artisan('cerne:merge-couple', ['principal' => 'ninguem@example.com', 'conjuge' => $this->conjuge->email])->assertFailed();
    }

    // ---- depois de unificar, o casal funciona como casal

    public function test_depois_de_unificar_a_privacidade_do_casal_vale_para_os_dois(): void
    {
        $daConjuge = ExpenseRecord::factory()->create(['profile_id' => $this->perfilConjuge->id, 'member_id' => $this->membroConjuge->id, 'is_private' => true]);
        $daPrincipal = ExpenseRecord::factory()->create(['profile_id' => $this->perfilPrincipal->id, 'member_id' => $this->membroPrincipal->id, 'is_private' => true]);

        $this->unificar();

        // A principal não enxerga o lançamento oculto da cônjuge, e vice-versa (privacidade simétrica).
        $this->actingAs($this->principal);
        app(ProfileContext::class)->set($this->perfilPrincipal->fresh(), $this->membroPrincipal->fresh());
        self::assertSame([$daPrincipal->id], ExpenseRecord::query()->pluck('id')->all());

        $this->actingAs($this->conjuge);
        app(ProfileContext::class)->set($this->perfilPrincipal->fresh(), $this->membroConjuge->fresh());
        self::assertSame([$daConjuge->id], ExpenseRecord::query()->pluck('id')->all());
    }
}
