<?php

namespace App\Livewire\Rules;

use App\Models\RuleReview;
use App\Models\RuleReviewer;
use App\Services\RuleReviewService;
use App\Support\GymExerciseClassifier;
use App\Support\Rules\RuleBook;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A página do profissional convidado: as regras do jeito que o app as usa hoje, com os exercícios que cada lista marca, e o parecer por regra.
 * Sem login e sem dado de pessoa alguma. O acesso é conferido a CADA ação: revogar corta na hora.
 */
#[Layout('components.layouts.reviewer')]
class RuleReviewPage extends Component
{
    #[Locked]
    public string $reviewerId = '';

    /** A regra aberta (uma por vez: as listas são longas). */
    public string $openRule = '';

    public ?string $decision = null;

    public string $comment = '';

    public string $missing = '';

    /** @var list<string> os exercícios que o revisor diz que não deveriam ser marcados */
    public array $wrong = [];

    public string $search = '';

    public function mount(string $token, RuleReviewService $service): void
    {
        $revisor = $service->findByToken($token);
        abort_if($revisor === null, 404);

        $revisor->update(['last_access_at' => now()]);
        $this->reviewerId = $revisor->id;
    }

    private function reviewer(): RuleReviewer
    {
        $revisor = RuleReviewer::query()->find($this->reviewerId);
        abort_if($revisor === null || ! $revisor->isActive(), 403);

        return $revisor;
    }

    public function open(string $chave, RuleReviewService $service): void
    {
        $revisor = $this->reviewer();
        abort_if(RuleBook::find($chave) === null, 404);

        $this->openRule = $this->openRule === $chave ? '' : $chave;
        $atual = $service->ofReviewer($revisor)->get($chave);
        $this->decision = $atual?->decision;
        $this->comment = (string) $atual?->comment;
        $this->missing = (string) $atual?->missing;
        $this->wrong = (array) ($atual?->wrong_items ?? []);
        $this->search = '';
        $this->resetErrorBag();
    }

    public function toggleWrong(string $nome): void
    {
        $this->reviewer();
        $this->wrong = in_array($nome, $this->wrong, true) ? array_values(array_diff($this->wrong, [$nome])) : [...$this->wrong, $nome];
    }

    public function decide(string $decisao, RuleReviewService $service): void
    {
        $this->decision = $decisao === RuleReview::APPROVED ? RuleReview::APPROVED : RuleReview::CHANGES;
        $this->saveDraft($service);
        $this->openRule = '';
    }

    public function saveDraft(RuleReviewService $service): void
    {
        $this->validate(['comment' => ['nullable', 'string', 'max:4000'], 'missing' => ['nullable', 'string', 'max:2000']], attributes: ['comment' => 'comentário', 'missing' => 'o que falta']);

        $service->saveDraft($this->reviewer(), $this->openRule, [
            'decision' => $this->decision, 'comment' => $this->comment, 'missing' => $this->missing, 'wrong_items' => $this->wrong,
        ]);
    }

    public function submit(RuleReviewService $service): void
    {
        try {
            $quantos = $service->submit($this->reviewer());
        } catch (DomainException $e) {
            $this->addError('submit', $e->getMessage());

            return;
        }

        if ($quantos === 0) {
            $this->addError('submit', 'Marque "Aprovar" ou "Pedir ajuste" em ao menos uma regra antes de enviar.');

            return;
        }

        session()->flash('status', $quantos === 1 ? 'Parecer de 1 regra enviado. Obrigado.' : "Parecer de {$quantos} regras enviado. Obrigado.");
    }

    public function render(RuleReviewService $service)
    {
        $revisor = $this->reviewer();
        $regras = RuleBook::all();
        $pareceres = $service->ofReviewer($revisor);
        $aberta = $regras[$this->openRule] ?? null;
        $marcados = $aberta !== null && $aberta['load'] !== null ? $service->matches($aberta['load']) : [];
        $busca = GymExerciseClassifier::normalize($this->search);

        return view('livewire.rules.rule-review-page', [
            'revisor' => $revisor,
            'regras' => $regras,
            'pareceres' => $pareceres,
            'marcados' => $busca === '' ? $marcados : array_values(array_filter($marcados, fn (string $n) => str_contains(GymExerciseClassifier::normalize($n), $busca))),
            'totalMarcados' => count($marcados),
            'catalogo' => $aberta !== null && $aberta['load'] !== null ? $service->catalogSize() : 0,
            'rascunhos' => $pareceres->filter(fn (RuleReview $r) => $r->submitted_at === null && $r->decision !== null)->count(),
        ]);
    }
}
