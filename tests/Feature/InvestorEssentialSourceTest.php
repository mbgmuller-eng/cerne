<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\EmploymentType;
use App\Enums\EssentialSource;
use App\Enums\InvestorType;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Enums\ReserveType;
use App\Livewire\Investments\InvestmentsIndex;
use App\Models\ConsultantClient;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FinancialReserve;
use App\Models\InvestorProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gasto essencial da reserva de paz: calculado pelo fluxo de caixa (padrão) ou informado à mão, para quem usa o Cerne só
 * nos investimentos. O valor informado é da pessoa (e do consultor): no casal, o cônjuge nem vê o número nem a reserva.
 */
class InvestorEssentialSourceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    private ProfileMember $bruno;

    private User $usuarioAna;

    private User $usuarioBruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuarioAna = User::factory()->create();
        $this->usuarioBruno = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioAna->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioAna->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->bruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioBruno->id, 'name' => 'Bruno']);

        $this->comoAna();
    }

    private function comoAna(): void
    {
        $this->actingAs($this->usuarioAna);
        app(ProfileContext::class)->set($this->perfil, $this->ana);
    }

    private function comoBruno(): void
    {
        $this->actingAs($this->usuarioBruno);
        app(ProfileContext::class)->set($this->perfil, $this->bruno);
    }

    private function essencial(?string $memberId, string $valor, bool $privado = false): void
    {
        ExpenseRecord::factory()->for($this->perfil, 'profile')->create([
            'member_id' => $memberId, 'necessity' => Necessity::Essential, 'amount' => $valor,
            'expense_date' => CarbonImmutable::now()->subMonth(), 'is_private' => $privado,
        ]);
    }

    private function perfilDe(ProfileMember $membro, EmploymentType $atuacao = EmploymentType::Clt, array $extra = []): InvestorProfile
    {
        return InvestorProfile::create(['member_id' => $membro->id, 'investor_type' => InvestorType::Moderate, 'employment_type' => $atuacao] + $extra);
    }

    public function test_perfil_novo_nasce_calculando_pelo_fluxo_de_caixa(): void
    {
        $this->essencial($this->ana->id, '2000.00');
        $perfil = $this->perfilDe($this->ana);

        self::assertSame(EssentialSource::Auto, $perfil->fresh()->essential_source);
        self::assertFalse($perfil->usesManualEssential());
        self::assertSame('0.00', InvestorProfile::query()->where('member_id', $this->bruno->id)->first()?->peaceReserveTarget() ?? '0.00');
    }

    public function test_quem_nao_lanca_gasto_tem_meta_zero_ate_informar_o_valor(): void
    {
        $perfil = $this->perfilDe($this->ana);
        self::assertSame('0.00', $perfil->peaceReserveTarget(), 'sem lançamento e sem valor informado, não há meta');

        $perfil->update(['essential_source' => EssentialSource::Manual, 'essential_monthly_manual' => '5000.00', 'essential_manual_updated_at' => now()]);

        // CLT: 9 meses.
        self::assertSame('45000.00', $perfil->fresh()->peaceReserveTarget());
        self::assertSame('15000.00', $perfil->fresh()->opportunityReserveTarget());
    }

    public function test_o_valor_informado_vale_mesmo_havendo_lancamentos_e_voltar_ao_automatico_volta_ao_calculo(): void
    {
        $this->essencial($this->ana->id, '2000.00');
        $perfil = $this->perfilDe($this->ana, extra: ['essential_source' => EssentialSource::Manual, 'essential_monthly_manual' => '5000.00']);

        self::assertSame('45000.00', $perfil->peaceReserveTarget());

        $perfil->update(['essential_source' => EssentialSource::Auto]);

        self::assertSame('18000.00', $perfil->fresh()->peaceReserveTarget(), '2000 x 9, o valor digitado fica guardado mas não vale');
        self::assertSame('5000.00', (string) $perfil->fresh()->essential_monthly_manual);
    }

    public function test_modo_manual_sem_valor_nao_vale(): void
    {
        $this->essencial($this->ana->id, '2000.00');
        $perfil = $this->perfilDe($this->ana, extra: ['essential_source' => EssentialSource::Manual]);

        self::assertFalse($perfil->usesManualEssential());
        self::assertSame('18000.00', $perfil->peaceReserveTarget());
    }

    public function test_no_casal_quem_informa_a_mao_sai_da_divisao_da_casa(): void
    {
        $this->essencial(null, '3000.00');
        $perfilAna = $this->perfilDe($this->ana, extra: ['essential_source' => EssentialSource::Manual, 'essential_monthly_manual' => '5000.00']);
        $perfilBruno = $this->perfilDe($this->bruno, EmploymentType::SelfEmployed);

        // Ana: o que digitou x 9 meses. Bruno: sozinho na divisão, a casa inteira x 12 meses.
        self::assertSame('45000.00', $perfilAna->peaceReserveTarget());
        self::assertSame('36000.00', $perfilBruno->peaceReserveTarget());
        self::assertSame('0.00', $perfilBruno->sharedPeaceReserveTarget(), 'sem dois provedores calculados, não há reserva do casal');
    }

    public function test_a_reserva_de_quem_informou_a_mao_fica_so_dele_no_casal(): void
    {
        $this->perfilDe($this->ana);
        $this->perfilDe($this->bruno, extra: ['essential_source' => EssentialSource::Manual, 'essential_monthly_manual' => '4000.00']);

        foreach ([$this->ana, $this->bruno] as $membro) {
            FinancialReserve::create(['profile_id' => $this->perfil->id, 'member_id' => $membro->id, 'reserve_type' => ReserveType::Paz, 'target_amount' => '0.00', 'current_amount' => '0.00']);
        }

        // A Ana (cônjuge) vê a reserva dela, não a do Bruno.
        $this->comoAna();
        self::assertSame([$this->ana->id], FinancialReserve::query()->pluck('member_id')->all());

        // O Bruno vê a dele.
        $this->comoBruno();
        self::assertEqualsCanonicalizing([$this->bruno->id], FinancialReserve::query()->whereNotNull('member_id')->where('member_id', $this->bruno->id)->pluck('member_id')->all());

        // O consultor vinculado vê tudo.
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuarioAna->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);
        self::assertSame(2, FinancialReserve::query()->count());
    }

    public function test_voltar_ao_automatico_devolve_a_visibilidade_da_reserva(): void
    {
        $this->perfilDe($this->ana);
        $perfilBruno = $this->perfilDe($this->bruno, extra: ['essential_source' => EssentialSource::Manual, 'essential_monthly_manual' => '4000.00']);
        FinancialReserve::create(['profile_id' => $this->perfil->id, 'member_id' => $this->bruno->id, 'reserve_type' => ReserveType::Paz, 'target_amount' => '0.00', 'current_amount' => '0.00']);

        $this->comoAna();
        self::assertSame(0, FinancialReserve::query()->count());

        $perfilBruno->update(['essential_source' => EssentialSource::Auto]);
        self::assertSame(1, FinancialReserve::query()->count());
    }

    public function test_a_pessoa_informa_o_valor_pela_tela_e_a_data_so_anda_quando_o_valor_muda(): void
    {
        $tela = Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestorProfileForm', $this->ana->id)
            ->set('investorTypeInput', InvestorType::Moderate->value)
            ->set('employmentTypeInput', EmploymentType::Clt->value)
            ->set('essentialSourceInput', 'manual')
            ->set('essentialManualInput', '6500.50')
            ->call('saveInvestorProfile')->assertHasNoErrors();

        $perfil = InvestorProfile::query()->where('member_id', $this->ana->id)->firstOrFail();
        self::assertSame(EssentialSource::Manual, $perfil->essential_source);
        self::assertSame('6500.50', (string) $perfil->essential_monthly_manual);
        self::assertNotNull($perfil->essential_manual_updated_at);
        self::assertSame('58504.50', $perfil->peaceReserveTarget());

        // Salvar de novo o mesmo valor não renova a data.
        $antiga = CarbonImmutable::parse('2026-01-10 10:00:00');
        $perfil->forceFill(['essential_manual_updated_at' => $antiga])->save();
        $tela->call('toggleInvestorProfileForm', $this->ana->id)
            ->assertSet('essentialSourceInput', 'manual')->assertSet('essentialManualInput', '6500.50')->call('saveInvestorProfile')->assertHasNoErrors();
        self::assertTrue($perfil->fresh()->essential_manual_updated_at->equalTo($antiga));

        // Mudou o valor: a data anda.
        $tela->call('toggleInvestorProfileForm', $this->ana->id)->set('essentialManualInput', '7000')->call('saveInvestorProfile')->assertHasNoErrors();
        self::assertTrue($perfil->fresh()->essential_manual_updated_at->gt($antiga));
    }

    public function test_valor_invalido_nao_salva(): void
    {
        foreach (['', '0', '-10', 'abc'] as $invalido) {
            Livewire::test(InvestmentsIndex::class)
                ->call('toggleInvestorProfileForm', $this->ana->id)
                ->set('investorTypeInput', InvestorType::Moderate->value)
                ->set('employmentTypeInput', EmploymentType::Clt->value)
                ->set('essentialSourceInput', 'manual')
                ->set('essentialManualInput', $invalido)
                ->call('saveInvestorProfile')->assertHasErrors('essentialManualInput');
        }

        self::assertSame(0, InvestorProfile::query()->count());
    }

    public function test_o_conjuge_nao_ve_nem_muda_o_valor_informado_pela_outra_pessoa(): void
    {
        $this->perfilDe($this->bruno, extra: ['essential_source' => EssentialSource::Manual, 'essential_monthly_manual' => '4321.00', 'essential_manual_updated_at' => now()]);

        // A Ana edita o perfil do Bruno (tipo de atuação): o gasto essencial dele não é dela.
        Livewire::test(InvestmentsIndex::class)
            ->call('toggleInvestorProfileForm', $this->bruno->id)
            ->assertSet('essentialManualInput', '')
            ->assertDontSee('4321')
            ->set('investorTypeInput', InvestorType::Moderate->value)
            ->set('employmentTypeInput', EmploymentType::SelfEmployed->value)
            ->set('essentialSourceInput', 'auto')
            ->set('essentialManualInput', '1')
            ->call('saveInvestorProfile')->assertHasNoErrors();

        $bruno = InvestorProfile::query()->where('member_id', $this->bruno->id)->firstOrFail();
        self::assertSame(EmploymentType::SelfEmployed, $bruno->employment_type);
        self::assertSame(EssentialSource::Manual, $bruno->essential_source, 'a origem dele não foi mexida pela Ana');
        self::assertSame('4321.00', (string) $bruno->essential_monthly_manual);
    }

    public function test_a_tela_mostra_o_valor_so_a_quem_informou_e_avisa_quando_esta_velho(): void
    {
        $this->perfilDe($this->ana, extra: ['essential_source' => EssentialSource::Manual, 'essential_monthly_manual' => '5000.00', 'essential_manual_updated_at' => now()->subMonths(7)]);
        $this->perfilDe($this->bruno, extra: ['essential_source' => EssentialSource::Manual, 'essential_monthly_manual' => '4321.00', 'essential_manual_updated_at' => now()]);

        $tela = Livewire::test(InvestmentsIndex::class);

        $tela->assertSee('Gasto essencial informado:')->assertSee('5.000,00')->assertSee('há mais de 6 meses')
            ->assertSee('informado pela própria pessoa')->assertDontSee('4.321,00');
    }

    public function test_quem_usa_o_fluxo_de_caixa_ve_a_media_e_o_aviso_de_zero(): void
    {
        $this->perfilDe($this->ana);

        Livewire::test(InvestmentsIndex::class)->call('toggleInvestorProfileForm', $this->ana->id)
            ->assertSee('Calcular pelo fluxo de caixa')->assertSee('Informar o valor')->assertSee('a meta da reserva fica em zero');

        $this->essencial($this->ana->id, '2500.00');

        Livewire::test(InvestmentsIndex::class)->call('toggleInvestorProfileForm', $this->ana->id)->assertSee('Média dos meses fechados: R$ 2.500,00');
    }
}
