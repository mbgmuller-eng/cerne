<?php

namespace App\Services;

use App\Models\EngagementPlanItem;
use App\Models\EngagementPlanStage;
use App\Models\EngagementPlanTemplate;
use App\Models\FinancialProfile;
use App\Support\Engagement\EngagementPlanCriteria;
use App\Support\Engagement\EngagementPlanTemplateFormat;
use App\Support\Engagement\EngagementViewer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Passo a passo (roteiro) do acompanhamento.
 *
 * O consultor monta e edita; o cliente só acompanha. Itens automáticos (`auto_check`) são marcados pelo que o app sabe do cliente
 * e reavaliados por syncRelation(), na abertura da tela e todo dia; itens manuais, só pelo consultor. O estado fica gravado
 * (done_at), para o PDF do plano e os avisos não dependerem de quem está logado.
 */
class EngagementPlanService
{
    // ------------------------------------------------------------------ montar o roteiro

    /** Acrescenta ao roteiro as etapas de um modelo escrito em texto (ver EngagementPlanTemplateFormat). */
    public function applyBody(EngagementViewer $v, string $texto): Collection
    {
        $this->somenteConsultor($v);

        $etapas = EngagementPlanTemplateFormat::parse($texto);

        return DB::transaction(function () use ($v, $etapas): Collection {
            $posicao = (int) EngagementPlanStage::query()->max('position');
            $criadas = collect();

            foreach ($etapas as $etapa) {
                $stage = EngagementPlanStage::create([
                    'profile_id' => $v->profileId, 'consultant_id' => $v->consultantId(), 'position' => ++$posicao, 'title' => $etapa['title'],
                ]);

                foreach ($etapa['items'] as $i => $item) {
                    EngagementPlanItem::create([
                        'profile_id' => $v->profileId, 'consultant_id' => $v->consultantId(), 'stage_id' => $stage->id,
                        'position' => $i + 1, 'title' => $item['title'], 'auto_check' => $item['auto'],
                    ]);
                }

                $criadas->push($stage);
            }

            $perfil = FinancialProfile::query()->findOrFail($v->profileId);
            $this->syncRelation($perfil, $v->consultantId());

            return $criadas;
        });
    }

    public function saveStage(EngagementViewer $v, ?EngagementPlanStage $etapa, string $titulo, ?string $descricao): EngagementPlanStage
    {
        $this->somenteConsultor($v);

        if ($etapa === null) {
            return EngagementPlanStage::create([
                'profile_id' => $v->profileId, 'consultant_id' => $v->consultantId(),
                'position' => (int) EngagementPlanStage::query()->max('position') + 1, 'title' => $titulo, 'description' => $descricao,
            ]);
        }

        $this->daRelacao($v, $etapa->consultant_id);
        $etapa->update(['title' => $titulo, 'description' => $descricao]);

        return $etapa;
    }

    public function deleteStage(EngagementViewer $v, EngagementPlanStage $etapa): void
    {
        $this->somenteConsultor($v);
        $this->daRelacao($v, $etapa->consultant_id);

        $etapa->delete();
    }

    /** Sobe (-1) ou desce (+1) a etapa na lista. */
    public function moveStage(EngagementViewer $v, EngagementPlanStage $etapa, int $direcao): void
    {
        $this->somenteConsultor($v);
        $this->daRelacao($v, $etapa->consultant_id);

        $etapas = EngagementPlanStage::query()->orderBy('position')->orderBy('created_at')->get()->values();
        $indice = $etapas->search(fn (EngagementPlanStage $e) => $e->id === $etapa->id);
        $alvo = $indice + ($direcao < 0 ? -1 : 1);

        if ($indice === false || $alvo < 0 || $alvo >= $etapas->count()) {
            return;
        }

        $reordenada = $etapas->all();
        [$reordenada[$indice], $reordenada[$alvo]] = [$reordenada[$alvo], $reordenada[$indice]];

        foreach ($reordenada as $i => $e) {
            $e->update(['position' => $i + 1]);
        }
    }

    public function saveItem(EngagementViewer $v, ?EngagementPlanItem $item, string $etapaId, string $titulo, ?string $auto): EngagementPlanItem
    {
        $this->somenteConsultor($v);

        $auto = ($auto === null || $auto === '') ? null : $auto;

        if ($auto !== null && ! EngagementPlanCriteria::exists($auto)) {
            throw new AuthorizationException('Critério automático desconhecido.');
        }

        $etapa = EngagementPlanStage::query()->findOrFail($etapaId);

        if ($item === null) {
            $item = EngagementPlanItem::create([
                'profile_id' => $v->profileId, 'consultant_id' => $v->consultantId(), 'stage_id' => $etapa->id,
                'position' => (int) EngagementPlanItem::query()->where('stage_id', $etapa->id)->max('position') + 1, 'title' => $titulo, 'auto_check' => $auto,
            ]);
        } else {
            $this->daRelacao($v, $item->consultant_id);
            // Trocar o critério zera o estado: o que estava marcado era de outro critério.
            $mudouCriterio = $item->auto_check !== $auto;
            $item->update(['title' => $titulo, 'auto_check' => $auto] + ($mudouCriterio ? ['done_at' => null, 'done_source' => null] : []));
        }

        $this->syncRelation(FinancialProfile::query()->findOrFail($v->profileId), $v->consultantId());

        return $item->fresh();
    }

    public function deleteItem(EngagementViewer $v, EngagementPlanItem $item): void
    {
        $this->somenteConsultor($v);
        $this->daRelacao($v, $item->consultant_id);

        $item->delete();
    }

    /** Marca ou desmarca um item manual. Item automático não se marca à mão: ele segue o que o app sabe. */
    public function setManualDone(EngagementViewer $v, EngagementPlanItem $item, bool $feito): EngagementPlanItem
    {
        $this->somenteConsultor($v);
        $this->daRelacao($v, $item->consultant_id);

        if ($item->isAuto()) {
            throw new AuthorizationException('Este item é marcado automaticamente.');
        }

        $item->update($feito ? ['done_at' => now(), 'done_source' => 'manual'] : ['done_at' => null, 'done_source' => null]);

        return $item;
    }

    // ------------------------------------------------------------------ modelos do consultor

    /** Guarda o roteiro atual do cliente como modelo do consultor (só estrutura: nada do estado nem do cliente). */
    public function saveAsTemplate(EngagementViewer $v, string $nome): EngagementPlanTemplate
    {
        $this->somenteConsultor($v);

        $etapas = EngagementPlanStage::query()->with('items')->orderBy('position')->get()->map(fn (EngagementPlanStage $e) => [
            'title' => $e->title,
            'items' => $e->items->map(fn (EngagementPlanItem $i) => ['title' => $i->title, 'auto' => $i->auto_check])->all(),
        ])->all();

        if ($etapas === []) {
            throw new AuthorizationException('O roteiro está vazio.');
        }

        return EngagementPlanTemplate::create(['consultant_id' => $v->userId, 'name' => $nome, 'body' => EngagementPlanTemplateFormat::serialize($etapas)]);
    }

    // ------------------------------------------------------------------ itens automáticos

    /**
     * Reavalia os itens automáticos do roteiro de uma relação (perfil + consultor) e grava o que mudou. Lê sem escopo e confere
     * os dados do cliente da perspectiva do consultor (ver EngagementPlanCriteria), então roda igual na tela e na rotina diária.
     *
     * @return int quantos itens mudaram de estado
     */
    public function syncRelation(FinancialProfile $perfil, string $consultantId): int
    {
        $itens = EngagementPlanItem::withoutGlobalScopes()
            ->where('profile_id', $perfil->id)->where('consultant_id', $consultantId)
            ->whereNotNull('auto_check')->get();

        if ($itens->isEmpty()) {
            return 0;
        }

        $resultado = EngagementPlanCriteria::evaluate($perfil, $itens->pluck('auto_check')->all());
        $mudaram = 0;

        foreach ($itens as $item) {
            $atendido = $resultado[$item->auto_check] ?? false;

            if ($atendido && $item->done_at === null) {
                $item->update(['done_at' => now(), 'done_source' => 'auto']);
                $mudaram++;
            } elseif (! $atendido && $item->done_at !== null) {
                $item->update(['done_at' => null, 'done_source' => null]);
                $mudaram++;
            }
        }

        return $mudaram;
    }

    /** Rotina diária: reavalia todos os roteiros com item automático. @return int itens que mudaram */
    public function syncAll(): int
    {
        $total = 0;

        EngagementPlanItem::withoutGlobalScopes()
            ->whereNotNull('auto_check')
            ->select('profile_id', 'consultant_id')->distinct()->get()
            ->each(function ($relacao) use (&$total): void {
                $perfil = FinancialProfile::query()->find($relacao->profile_id);

                // Só relações com vínculo ativo: o histórico de quem saiu fica parado.
                if ($perfil !== null && app(EngagementAccess::class)->activeConsultants($perfil)->pluck('id')->contains($relacao->consultant_id)) {
                    $total += $this->syncRelation($perfil, $relacao->consultant_id);
                }
            });

        return $total;
    }

    // ------------------------------------------------------------------ permissões

    private function somenteConsultor(EngagementViewer $v): void
    {
        if (! $v->isConsultant()) {
            throw new AuthorizationException('O roteiro é montado pelo consultor.');
        }
    }

    private function daRelacao(EngagementViewer $v, string $consultantId): void
    {
        if (! in_array($consultantId, $v->consultantIds, true)) {
            throw new AuthorizationException('Registro de outra relação de consultoria.');
        }
    }
}
