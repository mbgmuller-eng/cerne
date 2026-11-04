<?php

namespace Tests\Feature;

use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Services\FixedBillService;
use App\Services\InvestmentSnapshotService;
use App\Services\InvoiceService;
use App\Services\RecurringIncomeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rotina que grava ao abrir uma tela corre contra o cron e contra outra aba: as duas conferem "já existe?", as duas veem que não, e a segunda a gravar
 * esbarra no índice único. Foi o que derrubou /contas-fixas em produção (1062 em fixed_bill_payments). Aqui a "outra execução" grava a mesma linha
 * no instante entre a conferência e a gravação; a rotina tem de seguir sem erro e sem duplicar.
 */
class ConcurrentGenerationTest extends TestCase
{
    use RefreshDatabase;

    /** Faz a outra execução gravar a mesma linha, uma vez, logo antes da gravação da rotina. */
    private function outraExecucaoGravaPrimeiro(string $modelo, string $tabela): void
    {
        $feito = false;

        $modelo::creating(function (Model $linha) use ($tabela, &$feito): void {
            if ($feito) {
                return;
            }
            $feito = true;

            $tempos = array_filter([$linha->getCreatedAtColumn() => now(), $linha->getUpdatedAtColumn() => now()], fn ($valor, $coluna) => $coluna !== null && Schema::hasColumn($tabela, $coluna), ARRAY_FILTER_USE_BOTH);
            DB::table($tabela)->insert(array_merge($linha->getAttributes(), ['id' => (string) Str::uuid()], $tempos));
        });
    }

    public function test_conta_fixa_que_outra_execucao_ja_gerou_nao_derruba_a_tela_nem_duplica(): void
    {
        $perfil = FinancialProfile::factory()->create();
        $conta = FixedBill::factory()->for($perfil, 'profile')->create(['due_day' => 5]);
        $this->outraExecucaoGravaPrimeiro(FixedBillPayment::class, 'fixed_bill_payments');

        $criados = app(FixedBillService::class)->generateForMonth(2026, 8);

        $this->assertSame(0, $criados, 'quem perdeu a corrida não conta como criado');
        $this->assertSame(1, FixedBillPayment::withoutProfileScope()->where('fixed_bill_id', $conta->id)->count());
    }

    public function test_receita_recorrente_que_outra_execucao_ja_gerou_nao_derruba_a_tela_nem_duplica(): void
    {
        $perfil = FinancialProfile::factory()->create();
        $receita = RecurringIncome::factory()->for($perfil, 'profile')->create(['due_day' => 5]);
        $this->outraExecucaoGravaPrimeiro(RecurringIncomeOccurrence::class, 'recurring_income_occurrences');

        $criados = app(RecurringIncomeService::class)->generateForMonth(2026, 8);

        $this->assertSame(0, $criados);
        $this->assertSame(1, RecurringIncomeOccurrence::withoutProfileScope()->where('recurring_income_id', $receita->id)->count());
    }

    public function test_foto_mensal_que_outra_execucao_ja_gravou_nao_derruba_a_rotina_nem_duplica(): void
    {
        $perfil = FinancialProfile::factory()->create();
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id]);
        $ativo = InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['is_active' => true]);
        $this->outraExecucaoGravaPrimeiro(InvestmentSnapshot::class, 'investment_snapshots');

        $criadas = app(InvestmentSnapshotService::class)->captureMonth(2026, 8);

        $this->assertSame(0, $criadas);
        $this->assertSame(1, InvestmentSnapshot::withoutProfileScope()->where('investment_id', $ativo->id)->where('year', 2026)->where('month', 8)->count());
    }

    public function test_fatura_que_ja_existe_mas_a_busca_nao_enxerga_e_devolvida_em_vez_de_dar_erro(): void
    {
        $perfil = FinancialProfile::factory()->create();
        $cartao = CreditCard::factory()->for($perfil, 'profile')->create();
        // A rotina do cron roda sem perfil ativo: a busca do firstOrCreate não enxerga a fatura que a outra execução acabou de criar, tenta gravar e esbarra
        // no índice único. Tem de devolver a que já existe, não estourar 1062.
        $existente = CreditCardInvoice::withoutProfileScope()->create([
            'profile_id' => $perfil->id, 'credit_card_id' => $cartao->id, 'year' => 2026, 'month' => 8, 'closing_date' => '2026-08-20',
            'due_date' => '2026-08-28', 'total_amount' => '0.00', 'status' => 'open',
        ]);

        $fatura = app(InvoiceService::class)->ensureInvoice($cartao, 2026, 8);

        $this->assertSame($existente->id, $fatura->id);
        $this->assertSame(1, CreditCardInvoice::withoutProfileScope()->where('credit_card_id', $cartao->id)->count());
    }}
