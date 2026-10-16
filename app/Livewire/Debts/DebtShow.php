<?php

namespace App\Livewire\Debts;

use App\Enums\DebtAmortization;
use App\Enums\DebtInstallmentStatus;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ProfileMember;
use App\Services\DebtService;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Um contrato de dívida: saldo, cronograma e, em cada parcela, o pagamento ligado a uma despesa do fluxo de caixa.
 * Pagar cria a despesa; "vincular despesa" liga uma que já existe; desfazer solta a ligação sem apagar a despesa.
 */
#[Layout('components.layouts.app')]
class DebtShow extends Component
{
    public Debt $debt;

    // Pagar
    public bool $showPay = false;

    public ?string $payId = null;

    public string $payDate = '';

    public string $payAmount = '';

    public string $payAccountId = '';

    // Vincular a uma despesa existente
    public bool $showLink = false;

    public ?string $linkId = null;

    public string $linkExpenseId = '';

    public bool $linkReclassify = true;

    // Ajustar uma parcela em aberto
    public bool $showInstallment = false;

    public ?string $installmentId = null;

    public string $installmentAmount = '';

    public string $installmentDate = '';

    // Editar o contrato
    public bool $showEdit = false;

    public string $editName = '';

    public string $editCreditor = '';

    public string $editNecessity = 'essential';

    public string $editCategoryId = '';

    public string $editSubcategoryId = '';

    public string $editAccountId = '';

    public string $editMemberId = '';

    public bool $editPrivate = false;

    public string $editNotes = '';

    public bool $editReclassify = false;

    public function mount(Debt $debt): void
    {
        abort_if(auth()->user()?->isBroker(), 403);

        $this->debt = $debt;
    }

    // ------------------------------------------------------------------ pagar

    public function openPay(string $id): void
    {
        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id);

        $this->resetValidation();
        $this->payId = $id;
        $this->payDate = now()->toDateString();
        $this->payAmount = (string) $parcela->amount;
        $this->payAccountId = (string) $this->debt->bank_account_id;
        $this->showPay = true;
    }

    public function pay(): void
    {
        $dados = $this->validate([
            'payDate' => ['required', 'date_format:Y-m-d'],
            'payAmount' => ['required', 'string', 'max:20'],
            'payAccountId' => ['nullable', 'string'],
        ], [], ['payDate' => 'data do pagamento', 'payAmount' => 'valor pago']);

        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($this->payId);

        try {
            app(DebtService::class)->pay($parcela, CarbonImmutable::createFromFormat('Y-m-d', $dados['payDate'])->startOfDay(), $dados['payAmount'], $dados['payAccountId'] ?: null);
        } catch (InvalidArgumentException $e) {
            $this->addError('payAmount', $e->getMessage());

            return;
        }

        $this->showPay = false;
        $this->debt->refresh();
    }

    // ------------------------------------------------------------------ vincular

    public function openLink(string $id): void
    {
        DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id);

        $this->resetValidation();
        $this->linkId = $id;
        $this->linkExpenseId = '';
        $this->linkReclassify = true;
        $this->showLink = true;
    }

    public function linkExpense(): void
    {
        $this->validate(['linkExpenseId' => ['required', 'string']], [], ['linkExpenseId' => 'despesa']);

        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($this->linkId);
        $despesa = ExpenseRecord::query()->findOrFail($this->linkExpenseId);

        try {
            app(DebtService::class)->linkExpense($parcela, $despesa, $this->linkReclassify);
        } catch (InvalidArgumentException $e) {
            $this->addError('linkExpenseId', $e->getMessage());

            return;
        }

        $this->showLink = false;
        $this->debt->refresh();
    }

    public function unlink(string $id): void
    {
        app(DebtService::class)->unlink(DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id));

        $this->debt->refresh();
    }

    // ------------------------------------------------------------------ ajustar parcela

    public function openInstallment(string $id): void
    {
        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id);

        $this->resetValidation();
        $this->installmentId = $id;
        $this->installmentAmount = (string) $parcela->amount;
        $this->installmentDate = $parcela->due_date->toDateString();
        $this->showInstallment = true;
    }

    public function saveInstallment(): void
    {
        $dados = $this->validate([
            'installmentAmount' => ['required', 'string', 'max:20'],
            'installmentDate' => ['required', 'date_format:Y-m-d'],
        ], [], ['installmentAmount' => 'valor', 'installmentDate' => 'vencimento']);

        try {
            app(DebtService::class)->updateInstallment(
                DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($this->installmentId),
                $dados['installmentAmount'],
                CarbonImmutable::createFromFormat('Y-m-d', $dados['installmentDate']),
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('installmentAmount', $e->getMessage());

            return;
        }

        $this->showInstallment = false;
    }

    // ------------------------------------------------------------------ contrato

    public function openEdit(): void
    {
        $this->resetValidation();
        $this->editName = $this->debt->name;
        $this->editCreditor = (string) $this->debt->creditor;
        $this->editNecessity = $this->debt->necessity->value;
        $this->editCategoryId = (string) $this->debt->category_id;
        $this->editSubcategoryId = (string) $this->debt->subcategory_id;
        $this->editAccountId = (string) $this->debt->bank_account_id;
        $this->editMemberId = (string) $this->debt->member_id;
        $this->editPrivate = $this->debt->is_private;
        $this->editNotes = (string) $this->debt->notes;
        $this->editReclassify = false;
        $this->showEdit = true;
    }

    public function updatedEditCategoryId(): void
    {
        $this->editSubcategoryId = '';
    }

    public function saveEdit(): void
    {
        $dados = $this->validate([
            'editName' => ['required', 'string', 'max:120'],
            'editCreditor' => ['nullable', 'string', 'max:120'],
            'editNecessity' => ['required', 'in:'.Necessity::Essential->value.','.Necessity::Discretionary->value],
            'editCategoryId' => ['required', 'string'],
            'editSubcategoryId' => ['nullable', 'string'],
            'editAccountId' => ['nullable', 'string'],
            'editNotes' => ['nullable', 'string', 'max:2000'],
        ], [], ['editName' => 'nome', 'editCategoryId' => 'categoria']);

        $membro = $this->editMemberId !== '' && $this->membros()->contains('id', $this->editMemberId) ? $this->editMemberId : null;

        try {
            app(DebtService::class)->update($this->debt, [
                'name' => $dados['editName'], 'creditor' => $dados['editCreditor'] ?: null, 'necessity' => $dados['editNecessity'],
                'category_id' => $dados['editCategoryId'], 'subcategory_id' => $dados['editSubcategoryId'] ?: null,
                'bank_account_id' => $dados['editAccountId'] ?: null, 'notes' => $dados['editNotes'] ?: null,
                'member_id' => $membro, 'is_private' => $membro !== null && $this->editPrivate,
            ], $this->editReclassify);
        } catch (InvalidArgumentException $e) {
            $this->addError('editCategoryId', $e->getMessage());

            return;
        }

        $this->showEdit = false;
        $this->debt->refresh();
    }

    public function deleteDebt()
    {
        app(DebtService::class)->delete($this->debt);

        return $this->redirectRoute('debts.index');
    }

    /** @return Collection<int, ProfileMember> */
    private function membros(): Collection
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** Despesas que podem ter pago a parcela: perto da data e sem estar ligadas a outra parcela, as de valor mais parecido primeiro. */
    private function candidatas(): Collection
    {
        if ($this->linkId === null || ! $this->showLink) {
            return collect();
        }

        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->find($this->linkId);

        if ($parcela === null) {
            return collect();
        }

        return ExpenseRecord::query()
            ->with('category')
            ->whereDoesntHave('debtInstallment')
            ->where('is_refund', false)
            ->whereBetween('expense_date', [$parcela->due_date->copy()->subDays(45)->toDateString(), $parcela->due_date->copy()->addDays(45)->toDateString()])
            ->orderByRaw('ABS(amount - ?)', [(string) $parcela->amount])
            ->orderBy('expense_date')
            ->limit(15)
            ->get();
    }

    public function render()
    {
        // O projeto desliga o carregamento preguiçoso fora de produção: a categoria que a tela mostra vem carregada aqui.
        $this->debt->loadMissing(['category', 'subcategory']);

        $parcelas = $this->debt->installments()->with('expense.category')->get();
        $pagas = $parcelas->where('status', DebtInstallmentStatus::Paid);
        $abertas = $parcelas->where('status', DebtInstallmentStatus::Open);

        return view('livewire.debts.debt-show', [
            'parcelas' => $parcelas,
            'saldo' => $this->debt->balance(),
            'quantidadePagas' => $pagas->count(),
            'totalPago' => Money::sum($pagas->map(fn (DebtInstallment $p) => $p->paid_amount ?? $p->amount)),
            'proxima' => $abertas->first(),
            // Custo da dívida: tudo o que vai além do valor financiado (só quando o contrato separa os juros).
            'custoJuros' => $this->debt->amortization === DebtAmortization::Fixed ? null : bcadd(Money::sum($parcelas->pluck('interest_amount')), Money::sum($parcelas->pluck('fees_amount')), 2),
            'categorias' => ExpenseCategory::available()->whereNull('necessity')->with(['subcategories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name')])->get(),
            'contas' => BankAccount::query()->where('is_active', true)->orderBy('bank_name')->get(),
            'membros' => $this->membros(),
            'candidatas' => $this->candidatas(),
        ]);
    }
}
