<?php

namespace Tests\Feature;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\MemberRole;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Models\BankAccount;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\FixedBillReconciliationService;
use App\Services\FixedBillService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Conciliar conta fixa com lançamento que o extrato já trouxe: sugestão (regra ou nome, janela de 15 dias antes a 10 depois, um lançamento por conta),
 * a baixa ligada ao lançamento sem despesa nova nem mexer no saldo, privacidade e a tela. Hoje é 12/11/2026; as contas vencem em novembro.
 */
class FixedBillReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    private ProfileMember $bruno;

    private User $usuarioAna;

    private User $usuarioBruno;

    private BankAccount $conta;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00'));

        $this->usuarioAna = User::factory()->create();
        $this->usuarioBruno = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioAna->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioAna->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->bruno = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioBruno->id, 'role' => MemberRole::Secondary, 'name' => 'Bruno']);
        $this->como($this->usuarioAna, $this->ana);

        $this->conta = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'current_balance' => '10000.00']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function como(User $usuario, ProfileMember $membro): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro);
    }

    private function servico(): FixedBillReconciliationService
    {
        return app(FixedBillReconciliationService::class);
    }

    /** Conta mensal que vence em 10/11, com a ocorrência de novembro gerada. */
    private function conta(string $nome, string $valor, array $campos = []): FixedBill
    {
        $conta = FixedBill::factory()->create(array_merge(['profile_id' => $this->perfil->id, 'member_id' => null, 'name' => $nome, 'amount' => $valor, 'due_day' => 10], $campos));
        app(FixedBillService::class)->generateForMonth(2026, 11);

        return $conta;
    }

    private function ocorrencia(FixedBill $conta, int $mes = 11): FixedBillPayment
    {
        return FixedBillPayment::query()->where('fixed_bill_id', $conta->id)->where('month', $mes)->firstOrFail();
    }

    private function regra(FixedBill $conta, string $padrao, ?string $valor = null): ExpenseCategorizationRule
    {
        return ExpenseCategorizationRule::factory()->create(['profile_id' => $this->perfil->id, 'pattern' => $padrao, 'amount' => $valor, 'category_id' => $conta->category_id, 'fixed_bill_id' => $conta->id]);
    }

    private function lancamento(string $descricao, string $valor, string $data, array $campos = []): ExpenseRecord
    {
        return ExpenseRecord::factory()->create(array_merge([
            'profile_id' => $this->perfil->id, 'member_id' => null, 'description' => $descricao, 'amount' => $valor, 'expense_date' => $data, 'necessity' => 'essential',
            'bank_account_id' => $this->conta->id, 'is_private' => false,
        ], $campos));
    }

    // ------------------------------------------------------------------ sugestões

    public function test_o_lancamento_reconhecido_pela_regra_e_sugerido_mesmo_pago_uma_semana_antes(): void
    {
        $aluguel = $this->conta('Aluguel', '3254.68');
        $this->regra($aluguel, 'ORIGEM ASSESSORIA');
        $despesa = $this->lancamento('PIX ORIGEM ASSESSORIA LTDA', '3254.68', '2026-11-03');

        $sugestoes = $this->servico()->suggestions(2026, 11);

        self::assertCount(1, $sugestoes);
        self::assertSame($this->ocorrencia($aluguel)->id, $sugestoes[0]['payment']->id);
        self::assertSame($despesa->id, $sugestoes[0]['expense']->id);
        self::assertSame('rule', $sugestoes[0]['basis']);
        self::assertSame(7, $sugestoes[0]['distance']);
    }

    public function test_a_regra_que_trava_o_valor_so_vale_com_o_valor_exato(): void
    {
        $conta = $this->conta('Caetano', '900.00');
        $this->regra($conta, 'Caetano', '900.00');

        $this->lancamento('PIX Caetano', '850.00', '2026-11-03');
        self::assertSame([], $this->servico()->suggestions(2026, 11), 'valor diferente do que a regra trava');

        $this->lancamento('PIX Caetano', '900.00', '2026-11-04');
        self::assertCount(1, $this->servico()->suggestions(2026, 11));
    }

    public function test_sem_regra_o_nome_da_conta_na_descricao_com_o_mesmo_valor_basta(): void
    {
        $aluguel = $this->conta('Aluguel', '3254.68');
        $this->lancamento('Pagamento do Aluguel de novembro', '3254.68', '2026-11-05');

        $sugestoes = $this->servico()->suggestions(2026, 11);

        self::assertCount(1, $sugestoes);
        self::assertSame('name', $sugestoes[0]['basis']);

        // Valor diferente: só o nome não basta (casaria qualquer coisa que cite a palavra).
        ExpenseRecord::query()->delete();
        $this->lancamento('Pagamento do Aluguel de novembro', '100.00', '2026-11-05');
        self::assertSame([], $this->servico()->suggestions(2026, 11));
    }

    public function test_conta_de_valor_variavel_e_nome_curto_so_casam_pela_regra(): void
    {
        $luz = $this->conta('Luz - Copel', '300.00', ['is_variable' => true]);
        $gas = $this->conta('Gás', '120.00');

        $this->lancamento('Conta de Luz - Copel', '525.55', '2026-11-09');
        $this->lancamento('Compra de Gás', '120.00', '2026-11-09');
        self::assertSame([], $this->servico()->suggestions(2026, 11), 'variável e nome de 3 letras não casam só pelo nome');

        $this->regra($luz, 'COPEL');
        $sugestoes = $this->servico()->suggestions(2026, 11);
        self::assertCount(1, $sugestoes);
        self::assertSame($this->ocorrencia($luz)->id, $sugestoes[0]['payment']->id, 'a regra reconhece a luz mesmo com valor diferente do estimado');
    }

    public function test_a_janela_vai_de_15_dias_antes_a_10_dias_depois_do_vencimento(): void
    {
        $conta = $this->conta('Aluguel', '1000.00');
        $this->regra($conta, 'ORIGEM');

        foreach ([['2026-10-26', true], ['2026-10-25', false], ['2026-11-20', true], ['2026-11-21', false]] as [$data, $esperado]) {
            ExpenseRecord::query()->delete();
            $this->lancamento('PIX ORIGEM', '1000.00', $data);
            self::assertCount($esperado ? 1 : 0, $this->servico()->suggestions(2026, 11), "lançamento em {$data}");
        }
    }

    public function test_cada_conta_recebe_um_lancamento_e_cada_lancamento_baixa_uma_conta(): void
    {
        // Conta semanal: duas ocorrências abertas perto do mesmo lançamento. Fica com a mais próxima.
        $diarista = FixedBill::factory()->weekly(5)->create(['profile_id' => $this->perfil->id, 'member_id' => null, 'name' => 'Diarista', 'amount' => '200.00']);
        app(FixedBillService::class)->generateForMonth(2026, 11);
        $this->regra($diarista, 'DIARISTA');
        $despesa = $this->lancamento('PIX DIARISTA', '200.00', '2026-11-13'); // sexta

        $sugestoes = $this->servico()->suggestions(2026, 11);
        self::assertCount(1, $sugestoes, 'um lançamento só baixa uma ocorrência');
        self::assertSame('2026-11-13', $sugestoes[0]['payment']->due_date->toDateString(), 'a mais próxima do dia do lançamento');

        // O mesmo lançamento citado por duas contas: a que tem regra leva, a outra (só nome) fica sem.
        $this->conta('Diarista Casa', '200.00');
        self::assertCount(1, $this->servico()->suggestions(2026, 11));
        self::assertSame($despesa->id, $this->servico()->suggestions(2026, 11)[0]['expense']->id);
    }

    public function test_estorno_compra_de_cartao_e_lancamento_ja_ligado_nao_sao_candidatos(): void
    {
        $aluguel = $this->conta('Aluguel', '1000.00');
        $this->regra($aluguel, 'ORIGEM');

        $this->lancamento('ESTORNO ORIGEM', '1000.00', '2026-11-05', ['is_refund' => true]);
        $cartao = \App\Models\CreditCard::factory()->create(['profile_id' => $this->perfil->id]);
        $this->lancamento('ORIGEM no cartão', '1000.00', '2026-11-05', ['bank_account_id' => null, 'credit_card_id' => $cartao->id]);
        self::assertSame([], $this->servico()->suggestions(2026, 11));

        $ligado = $this->lancamento('PIX ORIGEM', '1000.00', '2026-11-06');
        $outra = $this->conta('Outra conta', '1000.00', ['due_day' => 10]);
        FixedBillPayment::query()->where('fixed_bill_id', $outra->id)->update(['status' => FixedBillPaymentStatus::Paid, 'expense_id' => $ligado->id]);
        self::assertSame([], $this->servico()->suggestions(2026, 11), 'o lançamento que já quitou outra conta não é oferecido de novo');
    }

    public function test_so_conta_em_aberto_do_mes_entra(): void
    {
        $aluguel = $this->conta('Aluguel', '1000.00');
        $this->regra($aluguel, 'ORIGEM');
        $this->lancamento('PIX ORIGEM', '1000.00', '2026-11-05');

        $this->ocorrencia($aluguel)->update(['status' => FixedBillPaymentStatus::Paid, 'amount_paid' => '1000.00', 'paid_at' => '2026-11-05']);
        self::assertSame([], $this->servico()->suggestions(2026, 11), 'já paga');

        $this->ocorrencia($aluguel)->update(['status' => FixedBillPaymentStatus::Skipped]);
        self::assertSame([], $this->servico()->suggestions(2026, 11), 'pulada');

        $this->ocorrencia($aluguel)->update(['status' => FixedBillPaymentStatus::Overdue]);
        self::assertCount(1, $this->servico()->suggestions(2026, 11), 'vencida continua em aberto');
    }

    // ------------------------------------------------------------------ privacidade

    public function test_lancamento_privado_nao_baixa_conta_de_todos_mas_baixa_conta_privada(): void
    {
        $compartilhada = $this->conta('Aluguel', '1000.00');
        $this->regra($compartilhada, 'ORIGEM');
        $this->lancamento('PIX ORIGEM', '1000.00', '2026-11-05', ['member_id' => $this->ana->id, 'is_private' => true]);

        self::assertSame([], $this->servico()->suggestions(2026, 11), 'o valor da despesa privada apareceria para o cônjuge na conta de todos');

        $privada = $this->conta('Presente surpresa', '400.00', ['member_id' => $this->ana->id, 'is_private' => true]);
        $this->regra($privada, 'LOJA');
        $this->lancamento('LOJA DO PRESENTE', '400.00', '2026-11-06', ['member_id' => $this->ana->id, 'is_private' => true]);

        $sugestoes = $this->servico()->suggestions(2026, 11);
        self::assertCount(1, $sugestoes);
        self::assertSame($this->ocorrencia($privada)->id, $sugestoes[0]['payment']->id);
    }

    public function test_cada_pessoa_so_enxerga_os_lancamentos_que_pode_ver(): void
    {
        $privada = $this->conta('Presente', '400.00', ['member_id' => $this->ana->id, 'is_private' => true]);
        $this->regra($privada, 'LOJA');
        $this->lancamento('LOJA DO PRESENTE', '400.00', '2026-11-06', ['member_id' => $this->ana->id, 'is_private' => true]);

        self::assertCount(1, $this->servico()->suggestions(2026, 11));

        $this->como($this->usuarioBruno, $this->bruno);
        self::assertSame([], $this->servico()->suggestions(2026, 11), 'o Bruno não enxerga a conta privada da Ana nem o lançamento dela');
    }

    // ------------------------------------------------------------------ conciliar

    public function test_conciliar_marca_paga_ligada_ao_lancamento_sem_despesa_nova_nem_mexer_no_saldo(): void
    {
        $aluguel = $this->conta('Aluguel', '3254.68');
        $this->regra($aluguel, 'ORIGEM');
        $despesa = $this->lancamento('PIX ORIGEM ASSESSORIA', '3254.68', '2026-11-03');
        $ocorrencia = $this->ocorrencia($aluguel);
        $despesas = ExpenseRecord::query()->count();

        $pagou = $this->servico()->reconcile($ocorrencia->id, $despesa->id);

        self::assertSame(FixedBillPaymentStatus::Paid, $pagou->status);
        self::assertSame('3254.68', (string) $pagou->amount_paid);
        self::assertSame('2026-11-03', $pagou->paid_at->toDateString(), 'a data do pagamento é a do lançamento');
        self::assertSame($despesa->id, $pagou->expense_id);
        self::assertSame($despesas, ExpenseRecord::query()->count(), 'nenhuma despesa nova');
        self::assertSame('10000.00', (string) $this->conta->fresh()->current_balance, 'o saldo não mexe: o dinheiro já saiu quando o lançamento entrou');
        self::assertSame([], $this->servico()->suggestions(2026, 11));
    }

    public function test_conciliar_conta_variavel_usa_o_valor_do_lancamento(): void
    {
        $luz = $this->conta('Luz - Copel', '300.00', ['is_variable' => true]);
        $this->regra($luz, 'COPEL');
        $despesa = $this->lancamento('COPEL DISTRIBUICAO', '525.55', '2026-11-09');

        $pagou = $this->servico()->reconcile($this->ocorrencia($luz)->id, $despesa->id);

        self::assertSame('525.55', (string) $pagou->amount_paid);
    }

    public function test_as_recusas_do_conciliar_dizem_o_motivo(): void
    {
        $aluguel = $this->conta('Aluguel', '1000.00');
        $this->regra($aluguel, 'ORIGEM');
        $despesa = $this->lancamento('PIX ORIGEM', '1000.00', '2026-11-05');
        $ocorrencia = $this->ocorrencia($aluguel);

        $recusa = function (callable $fn): string {
            try {
                $fn();
            } catch (\InvalidArgumentException $e) {
                return $e->getMessage();
            }

            return 'não recusou';
        };

        // fora da janela
        $longe = $this->lancamento('PIX ORIGEM', '1000.00', '2026-12-30');
        self::assertStringContainsString('longe do vencimento', $recusa(fn () => $this->servico()->reconcile($ocorrencia->id, $longe->id)));

        // estorno
        $estorno = $this->lancamento('ESTORNO', '1000.00', '2026-11-05', ['is_refund' => true]);
        self::assertStringContainsString('não é um pagamento', $recusa(fn () => $this->servico()->reconcile($ocorrencia->id, $estorno->id)));

        // privado x conta de todos
        $privado = $this->lancamento('PIX ORIGEM', '1000.00', '2026-11-05', ['member_id' => $this->ana->id, 'is_private' => true]);
        self::assertStringContainsString('é privado', $recusa(fn () => $this->servico()->reconcile($ocorrencia->id, $privado->id)));

        // conclui, e depois: conta já paga e lançamento já ligado
        $this->servico()->reconcile($ocorrencia->id, $despesa->id);
        self::assertStringContainsString('já foi paga', $recusa(fn () => $this->servico()->reconcile($ocorrencia->id, $despesa->id)));

        $outra = $this->conta('Outra', '1000.00');
        self::assertStringContainsString('já está ligado', $recusa(fn () => $this->servico()->reconcile($this->ocorrencia($outra)->id, $despesa->id)));
    }

    public function test_id_de_outro_perfil_ou_inventado_nao_concilia(): void
    {
        $aluguel = $this->conta('Aluguel', '1000.00');
        $despesa = $this->lancamento('PIX', '1000.00', '2026-11-05');

        $outro = FinancialProfile::factory()->create();
        $alheia = FixedBill::factory()->create(['profile_id' => $outro->id, 'due_day' => 10]);
        $pagamentoAlheio = FixedBillPayment::create(['profile_id' => $outro->id, 'fixed_bill_id' => $alheia->id, 'year' => 2026, 'month' => 11, 'due_date' => '2026-11-10', 'status' => FixedBillPaymentStatus::Pending]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->servico()->reconcile($pagamentoAlheio->id, $despesa->id);
    }

    public function test_o_indice_unico_impede_o_mesmo_lancamento_em_duas_contas(): void
    {
        $a = $this->conta('Conta A', '100.00');
        $b = $this->conta('Conta B', '100.00');
        $despesa = $this->lancamento('PIX', '100.00', '2026-11-05');
        $this->ocorrencia($a)->update(['expense_id' => $despesa->id]);

        $this->expectException(QueryException::class);
        $this->ocorrencia($b)->update(['expense_id' => $despesa->id]);
    }

    public function test_pagar_pelo_cerne_guarda_a_ligacao_e_essa_despesa_nunca_e_oferecida_para_conciliar(): void
    {
        $categoria = \App\Models\ExpenseCategory::factory()->shared()->create();
        $diarista = FixedBill::factory()->weekly(5)->create([
            'profile_id' => $this->perfil->id, 'member_id' => null, 'name' => 'Diarista', 'amount' => '200.00', 'category_id' => $categoria->id,
            'bank_account_id' => $this->conta->id, 'subcategory_id' => null,
        ]);
        app(FixedBillService::class)->generateForMonth(2026, 11);
        $primeira = FixedBillPayment::query()->where('fixed_bill_id', $diarista->id)->orderBy('due_date')->first();

        app(FixedBillService::class)->pay($primeira, null, CarbonImmutable::parse('2026-11-06'), $this->usuarioAna->id);

        $primeira->refresh();
        self::assertNotNull($primeira->expense_id);
        self::assertSame('Diarista', ExpenseRecord::query()->findOrFail($primeira->expense_id)->description);

        // A despesa gerada se chama "Diarista", com o valor da conta, a 7 dias da próxima ocorrência: sem a ligação, seria sugerida para ela.
        self::assertSame([], $this->servico()->suggestions(2026, 11));
    }

    // ------------------------------------------------------------------ tela

    public function test_a_tela_mostra_as_sugestoes_com_tudo_marcado_e_concilia_so_as_marcadas(): void
    {
        $aluguel = $this->conta('Aluguel', '3254.68');
        $luz = $this->conta('Luz - Copel', '300.00', ['is_variable' => true]);
        $this->regra($aluguel, 'ORIGEM');
        $this->regra($luz, 'COPEL');
        $this->lancamento('PIX ORIGEM ASSESSORIA', '3254.68', '2026-11-03');
        $this->lancamento('COPEL DISTRIBUICAO', '525.55', '2026-11-09');
        $despesas = ExpenseRecord::query()->count();

        $c = Livewire::test(FixedBillsIndex::class)
            ->assertSeeHtml('data-reconcile-panel')
            ->assertSee('Contas que parecem já pagas pelo extrato')
            ->assertSee('Aluguel')->assertSee('reconhecido pela regra de categorização')
            ->assertSee('2 contas passam a pagas, ligadas ao lançamento do extrato. Nenhuma despesa nova é criada e o saldo das contas não muda.')
            ->assertSet('reconcileSelection', fn ($ids) => count($ids) === 2);

        // Desmarca a luz: só o aluguel é conciliado.
        $c->set('reconcileSelection', [$this->ocorrencia($aluguel)->id])
            ->assertSee('1 conta passa a paga, ligada ao lançamento do extrato.')
            ->call('reconcileSelected')
            ->assertSee('1 conta conciliada com o lançamento do extrato.')
            // A conciliada sai do painel na mesma resposta (a lista não pode ficar em cache) e a seleção passa a ser só o que sobrou.
            ->assertSet('reconcileSelection', [$this->ocorrencia($luz)->id])
            ->assertDontSee('PIX ORIGEM ASSESSORIA')
            ->assertSee('COPEL DISTRIBUICAO');

        self::assertSame(FixedBillPaymentStatus::Paid, $this->ocorrencia($aluguel)->status);
        self::assertSame(FixedBillPaymentStatus::Pending, $this->ocorrencia($luz)->status);
        self::assertSame($despesas, ExpenseRecord::query()->count());
        self::assertSame('10000.00', (string) $this->conta->fresh()->current_balance);
    }

    public function test_sem_sugestao_o_painel_nem_aparece(): void
    {
        $this->conta('Aluguel', '1000.00');

        Livewire::test(FixedBillsIndex::class)->assertDontSeeHtml('data-reconcile-panel');
    }

    public function test_a_tela_so_concilia_o_que_o_servidor_sugere_agora_e_ignora_id_marcado_a_mais(): void
    {
        $aluguel = $this->conta('Aluguel', '1000.00');
        $outra = $this->conta('Outra conta', '700.00');
        $this->regra($aluguel, 'ORIGEM');
        $this->lancamento('PIX ORIGEM', '1000.00', '2026-11-05');

        Livewire::test(FixedBillsIndex::class)
            ->set('reconcileSelection', [$this->ocorrencia($aluguel)->id, $this->ocorrencia($outra)->id, 'inventado'])
            ->call('reconcileSelected');

        self::assertSame(FixedBillPaymentStatus::Paid, $this->ocorrencia($aluguel)->status);
        self::assertSame(FixedBillPaymentStatus::Pending, $this->ocorrencia($outra)->status, 'sem lançamento sugerido, marcar o id no navegador não baixa a conta');
    }

    public function test_mudar_de_mes_refaz_a_selecao(): void
    {
        $aluguel = $this->conta('Aluguel', '1000.00');
        $this->regra($aluguel, 'ORIGEM');
        $this->lancamento('PIX ORIGEM', '1000.00', '2026-11-05');

        Livewire::test(FixedBillsIndex::class)
            ->assertSet('reconcileSelection', fn ($ids) => count($ids) === 1)
            ->call('nextMonth')->assertSet('reconcileSelection', [])->assertDontSeeHtml('data-reconcile-panel')
            ->call('previousMonth')->assertSet('reconcileSelection', fn ($ids) => count($ids) === 1);
    }

    public function test_a_tela_mostra_a_recusa_quando_o_lancamento_deixou_de_servir(): void
    {
        $aluguel = $this->conta('Aluguel', '1000.00');
        $this->regra($aluguel, 'ORIGEM');
        $despesa = $this->lancamento('PIX ORIGEM', '1000.00', '2026-11-05');

        $c = Livewire::test(FixedBillsIndex::class);
        // Entre abrir a tela e confirmar, outra aba liga o lançamento a outra conta.
        $outra = $this->conta('Outra', '1000.00');
        $this->ocorrencia($outra)->update(['status' => FixedBillPaymentStatus::Paid, 'expense_id' => $despesa->id]);

        $c->call('reconcileSelected')->assertSee('Nenhuma conta foi conciliada.');
        self::assertSame(FixedBillPaymentStatus::Pending, $this->ocorrencia($aluguel)->status);
    }
}
