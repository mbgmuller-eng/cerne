<?php

namespace App\Livewire\Budget;

use App\Livewire\Concerns\EditsBudgetStructure;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\BudgetRuleTemplate;
use App\Models\Goal;
use App\Services\BudgetRuleService;
use App\Services\GoalAllocationService;
use App\Support\Budget\BudgetStructure;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Regra de orçamento do perfil: a meta de cada área e de cada item de investimento sobre a receita do mês, o que foi lançado, o que falta, a divisão do
 * balde de objetivos entre os objetivos e o editor da regra. O cálculo é `BudgetRuleService`; aqui só se escolhe o mês, edita a regra e aplica o sugerido.
 *
 * Os números são os que QUEM está olhando enxerga (privacidade do casal); a regra em si é do perfil inteiro. Corretor não entra (só Seguros).
 */
#[Layout('components.layouts.app')]
class BudgetRuleIndex extends Component
{
    use EditsBudgetStructure, RequiresActiveProfile;

    /** Mês em exibição, AAAA-MM, na URL. */
    #[Url(as: 'mes')]
    public string $ym = '';

    public bool $showEditor = false;

    /** A regra vale a partir deste mês (AAAA-MM). */
    public string $startYm = '';

    public string $goalMethod = 'priority';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        abort_if(auth()->user()?->isBroker(), 403);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->ym) !== 1) {
            $this->ym = CarbonImmutable::now()->format('Y-m');
        }
    }

    // ------------------------------------------------------------------ mês

    /** @return array{0: int, 1: int} */
    private function period(): array
    {
        return [(int) substr($this->ym, 0, 4), (int) substr($this->ym, 5, 2)];
    }

    public function previousMonth(): void
    {
        $this->ym = CarbonImmutable::createFromFormat('Y-m-d', $this->ym.'-01')->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->ym = CarbonImmutable::createFromFormat('Y-m-d', $this->ym.'-01')->addMonthNoOverflow()->format('Y-m');
    }

    public function currentMonth(): void
    {
        $this->ym = CarbonImmutable::now()->format('Y-m');
    }

    // ------------------------------------------------------------------ editor da regra

    public function openEditor(): void
    {
        [$ano, $mes] = $this->period();
        $regra = app(BudgetRuleService::class)->ruleFor($ano, $mes);

        $this->loadDraft($regra !== null ? (array) $regra->structure : BudgetStructure::default());
        // O padrão é valer do mês que se está olhando em diante: mudar a regra não reescreve os meses passados.
        $this->startYm = $this->ym;
        $this->showEditor = true;
    }

    /** Começa pelo modelo pronto (ou pelo modelo do consultor, em `useTemplate`), já aberto no editor. */
    public function startWith(string $chave): void
    {
        $this->usePreset($chave);
        $this->startYm = $this->ym;
        $this->showEditor = true;
    }

    public function useTemplate(string $id): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);

        $modelo = BudgetRuleTemplate::query()->findOrFail($id);

        $this->loadDraft((array) $modelo->structure);
        $this->startYm = $this->ym;
        $this->showEditor = true;
    }

    public function closeEditor(): void
    {
        $this->showEditor = false;
        $this->resetValidation();
    }

    public function saveRule(): void
    {
        $inicio = $this->parseStart();

        if ($inicio === null) {
            $this->addError('startYm', 'Escolha o mês de início da regra.');

            return;
        }

        $estrutura = $this->draftNormalized();
        $erros = BudgetStructure::validate($estrutura);

        // O quadro de erros do editor (editorErros) já lista, ao vivo, tudo o que impede de salvar.
        if ($erros !== []) {
            return;
        }

        app(BudgetRuleService::class)->save((string) app(ProfileContext::class)->profileId(), $inicio[0], $inicio[1], $estrutura, auth()->id());

        $this->showEditor = false;
        $this->ym = sprintf('%04d-%02d', $inicio[0], $inicio[1]);
        session()->flash('budget_status', 'Regra salva. Vale a partir de '.sprintf('%02d/%04d', $inicio[1], $inicio[0]).'; os meses anteriores seguem a regra que valia.');
    }

    /** Tira a versão da regra que vale para o mês em exibição (volta a valer a anterior, se houver). */
    public function deleteRule(): void
    {
        [$ano, $mes] = $this->period();
        $regra = app(BudgetRuleService::class)->ruleFor($ano, $mes);

        if ($regra !== null) {
            $regra->delete();
            session()->flash('budget_status', 'Regra removida.');
        }
    }

    /** @return array{0: int, 1: int}|null */
    private function parseStart(): ?array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $this->startYm, $m) !== 1) {
            return null;
        }

        $ano = (int) $m[1];

        return $ano >= 2020 && $ano <= CarbonImmutable::now()->year + 2 ? [$ano, (int) $m[2]] : null;
    }

    // ------------------------------------------------------------------ divisão entre objetivos

    /** Grava o sugerido como "Quanto vou guardar por mês" dos objetivos. A conta é refeita AQUI, nunca lida do navegador. */
    public function applyGoals(): void
    {
        [$ano, $mes] = $this->period();
        $relatorio = app(BudgetRuleService::class)->report($ano, $mes);
        $pool = $relatorio === null ? null : $this->goalPool($relatorio);

        if ($pool === null) {
            return;
        }

        $alocacao = app(GoalAllocationService::class)->allocate($pool, $this->goalsForAllocation(), $this->goalMethod);
        $valores = [];

        foreach ($alocacao['rows'] as $linha) {
            $valores[$linha['goal']->id] = $linha['suggested'];
        }

        $gravados = app(GoalAllocationService::class)->apply($valores);

        session()->flash('budget_status', $gravados === 0
            ? 'Nenhum objetivo foi atualizado.'
            : ($gravados === 1 ? '1 objetivo atualizado' : $gravados.' objetivos atualizados').' com o valor sugerido por mês.');
    }

    /** @return array<string, mixed>|null */
    private function goalItem(array $relatorio): ?array
    {
        foreach ($relatorio['areas']['investment']['groups'] as $grupo) {
            foreach ($grupo['items'] as $item) {
                if ($item['kind'] === 'goals') {
                    return $item;
                }
            }
        }

        return null;
    }

    private function goalPool(array $relatorio): ?string
    {
        return $this->goalItem($relatorio)['target'] ?? null;
    }

    /** @return list<Goal> */
    private function goalsForAllocation(): array
    {
        return Goal::query()->active()->byPriority()->withContributionTotal()->withInvestmentCount()->with('investments', 'linkedDebt')->get()->all();
    }

    // ------------------------------------------------------------------ tela

    public function render()
    {
        [$ano, $mes] = $this->period();
        $servico = app(BudgetRuleService::class);
        $relatorio = $servico->report($ano, $mes);
        $regra = $servico->ruleFor($ano, $mes);

        $alocacao = null;
        $linhasObjetivos = [];

        if ($relatorio !== null && ($pool = $this->goalPool($relatorio)) !== null) {
            $alocacao = app(GoalAllocationService::class)->allocate($pool, $this->goalsForAllocation(), $this->goalMethod);

            foreach ($alocacao['rows'] as $linha) {
                if (bccomp($linha['suggested'], '0', 2) > 0 && $linha['goal']->isEditableByCurrentMember() && $linha['goal']->linked_debt_id === null) {
                    $atual = $linha['goal']->installment_amount !== null ? Money::format($linha['goal']->installment_amount).' por mês' : 'sem valor definido';
                    $linhasObjetivos[] = $linha['goal']->name.': de '.$atual.' para '.Money::format($linha['suggested']).' por mês.';
                }
            }
        }

        $erros = [];
        $previa = null;

        if ($this->showEditor) {
            $erros = $this->draftErrors();
            $previa = $erros === [] ? $servico->report($ano, $mes, $this->draftNormalized()) : null;
        }

        return view('livewire.budget.budget-rule-index', [
            'ano' => $ano,
            'mes' => $mes,
            'relatorio' => $relatorio,
            'regra' => $regra,
            'alocacao' => $alocacao,
            'linhasObjetivos' => $linhasObjetivos,
            'editorErros' => $erros,
            'previa' => $previa,
            'categoryOptions' => $this->showEditor ? $this->categoryOptions() : [],
            'presets' => BudgetStructure::presets(),
            'modelos' => auth()->user()?->isConsultant() ? BudgetRuleTemplate::query()->orderBy('name')->get() : collect(),
            'ehMesAtual' => $this->ym === CarbonImmutable::now()->format('Y-m'),
        ]);
    }
}
