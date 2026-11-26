<?php

namespace App\Services;

use App\Enums\MovementLoad;
use App\Models\GymExerciseCatalog;
use App\Models\RuleReview;
use App\Models\RuleReviewer;
use App\Models\User;
use App\Support\Rules\RuleBook;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Convite do revisor, o parecer sobre cada regra e a pergunta que as telas fazem: "esta regra, do jeito que está hoje, foi aprovada por um
 * profissional?". Aprovação vale para a VERSÃO revisada. Nada do que o revisor escreve muda uma regra: as mudanças são feitas no código.
 */
class RuleReviewService
{
    /** Por quantos dias o link do revisor funciona. */
    public const LINK_DAYS = 60;

    /** @var array<string, ?array{name: string, registry: string, on: \Carbon\CarbonInterface}> */
    private array $memo = [];

    /** @return array{0: RuleReviewer, 1: string} o revisor e o link, mostrado UMA vez (o banco guarda só o hash) */
    public function invite(string $nome, string $registro, ?string $email, User $quemConvida): array
    {
        $token = Str::random(48);

        $revisor = RuleReviewer::create([
            'name' => trim($nome),
            'registry' => trim($registro),
            'email' => $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
            'token_hash' => hash('sha256', $token),
            'invited_by_user_id' => $quemConvida->id,
            'expires_at' => now()->addDays(self::LINK_DAYS),
        ]);

        return [$revisor, route('rules.review', $token)];
    }

    public function revoke(RuleReviewer $revisor): void
    {
        $revisor->update(['revoked_at' => now()]);
    }

    public function findByToken(string $token): ?RuleReviewer
    {
        $revisor = RuleReviewer::query()->where('token_hash', hash('sha256', $token))->first();

        return $revisor !== null && $revisor->isActive() ? $revisor : null;
    }

    /** Guarda o rascunho de um parecer sobre a versão ATUAL da regra. Mudar um parecer já enviado volta a ser rascunho. */
    public function saveDraft(RuleReviewer $revisor, string $chave, array $dados): RuleReview
    {
        $regra = RuleBook::find($chave);

        if ($regra === null || ! $revisor->isActive()) {
            throw new DomainException('Este acesso não pode mais registrar pareceres.');
        }

        $decisao = in_array($dados['decision'] ?? null, [RuleReview::APPROVED, RuleReview::CHANGES], true) ? $dados['decision'] : null;

        return RuleReview::query()->updateOrCreate(
            ['reviewer_id' => $revisor->id, 'rule_key' => $chave, 'rule_version' => $regra['version']],
            [
                'decision' => $decisao,
                'comment' => mb_substr(trim((string) ($dados['comment'] ?? '')), 0, 4000) ?: null,
                'wrong_items' => array_slice(array_values(array_unique(array_filter((array) ($dados['wrong_items'] ?? []), 'is_string'))), 0, 500),
                'missing' => mb_substr(trim((string) ($dados['missing'] ?? '')), 0, 2000) ?: null,
                'submitted_at' => null,
            ],
        );
    }

    /** Envia o que está em rascunho e tem decisão. Devolve quantos foram. */
    public function submit(RuleReviewer $revisor): int
    {
        if (! $revisor->isActive()) {
            throw new DomainException('Este acesso não pode mais registrar pareceres.');
        }

        $this->memo = [];

        return $revisor->reviews()->whereNull('submitted_at')->whereNotNull('decision')->update(['submitted_at' => now()]);
    }

    /** @return Collection<string, RuleReview> o que este revisor já registrou nas versões atuais, pela chave da regra */
    public function ofReviewer(RuleReviewer $revisor): Collection
    {
        $versoes = array_column(RuleBook::all(), 'version', 'key');

        return $revisor->reviews()->get()->filter(fn (RuleReview $r) => ($versoes[$r->rule_key] ?? null) === $r->rule_version)->keyBy('rule_key');
    }

    /**
     * Quem aprovou a versão atual da regra, ou nulo. Vale o ÚLTIMO parecer enviado sobre essa versão: um pedido de ajuste depois de uma
     * aprovação tira a aprovação.
     *
     * @return array{name: string, registry: string, on: \Carbon\CarbonInterface}|null
     */
    public function approval(string $chave): ?array
    {
        if (array_key_exists($chave, $this->memo)) {
            return $this->memo[$chave];
        }

        $regra = RuleBook::find($chave);
        $ultimo = $regra === null ? null : RuleReview::query()->where('rule_key', $chave)->where('rule_version', $regra['version'])
            ->whereNotNull('submitted_at')->with('reviewer')->orderByDesc('submitted_at')->orderByDesc('updated_at')->first();

        return $this->memo[$chave] = $ultimo !== null && $ultimo->decision === RuleReview::APPROVED
            ? ['name' => $ultimo->reviewer->name, 'registry' => $ultimo->reviewer->registry, 'on' => $ultimo->submitted_at]
            : null;
    }

    /** @return list<string> os exercícios do catálogo do Cerne que a lista de uma carga marca hoje */
    public function matches(string $carga): array
    {
        $tipo = MovementLoad::tryFrom($carga);

        if ($tipo === null) {
            return [];
        }

        // Só o catálogo padrão do sistema: exercício que alguém criou nunca aparece para o revisor.
        return GymExerciseCatalog::withoutTaxonomyScope()->whereNull('profile_id')->where('is_active', true)->orderBy('name')->pluck('name')
            ->filter(fn (string $nome) => $tipo->matches($nome))->values()->all();
    }

    public function catalogSize(): int
    {
        return GymExerciseCatalog::withoutTaxonomyScope()->whereNull('profile_id')->where('is_active', true)->count();
    }
}
