<?php

namespace App\Services;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\GymSeason;
use App\Enums\GymSport;
use App\Models\GymEquipment;
use App\Models\GymExercise;
use App\Models\GymExerciseCatalog;
use App\Models\GymExerciseSuggestion;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Montagem do plano de academia: fase, treinos da rotação, exercícios de
 * cada treino. Tudo roda sob o ProfileContext da pessoa (o escopo
 * PersonalHealthScope filtra e a trait carimba o dono) — este serviço não
 * escolhe de quem é o dado.
 */
class GymPlanService
{
    public function activePlan(): ?GymPlan
    {
        return GymPlan::query()->where('is_active', true)->latest('created_at')->first();
    }

    /** Uma fase ativa por vez: abrir a nova encerra a anterior (o histórico fica). */
    public function createPlan(string $name): GymPlan
    {
        return DB::transaction(function () use ($name): GymPlan {
            GymPlan::query()->where('is_active', true)->update([
                'is_active' => false,
                'ended_on' => now()->toDateString(),
            ]);

            return GymPlan::create([
                'name' => trim($name),
                'is_active' => true,
                'started_on' => now()->toDateString(),
            ]);
        });
    }

    public function addWorkout(GymPlan $plan, string $name, ?string $focus): GymWorkout
    {
        $proxima = (int) GymWorkout::query()->where('plan_id', $plan->id)->max('position') + 1;

        return GymWorkout::create([
            'plan_id' => $plan->id,
            'name' => trim($name),
            'focus' => $focus !== null && trim($focus) !== '' ? trim($focus) : null,
            'position' => $proxima,
        ]);
    }

    /**
     * Treino que já tem sessão registrada não some: excluir apagaria o
     * histórico junto (as sessões dependem dele). Fica pra uma futura
     * opção de arquivar.
     */
    public function deleteWorkout(GymWorkout $workout): void
    {
        if (GymSession::query()->where('workout_id', $workout->id)->exists()) {
            throw new DomainException('Este treino já tem sessões registradas e não pode ser excluído.');
        }

        DB::transaction(function () use ($workout): void {
            $planId = $workout->plan_id;
            $workout->delete();
            $this->renumber(GymWorkout::query()->where('plan_id', $planId));
        });
    }

    public function moveWorkout(GymWorkout $workout, int $direction): void
    {
        $this->move($workout, GymWorkout::query()->where('plan_id', $workout->plan_id), $direction);
    }

    /**
     * `$sugerirAoCatalogo` só é ligado pela tela de montar o treino: exercício
     * NOVO que não existe no catálogo compartilhado entra na fila anônima do
     * admin (GymExerciseSuggestion). A importação de histórico não liga —
     * uma planilha com dezenas de nomes soltos inundaria a fila.
     */
    public function findOrCreateExercise(string $name, GymMuscleGroup $group, GymMeasureType $type, bool $sugerirAoCatalogo = false): GymExercise
    {
        $name = trim($name);

        // O nome é único por pessoa (índice); reaproveitar em vez de falhar
        // faz "Supino reto" digitado de novo apontar pro mesmo exercício.
        $existente = GymExercise::query()->where('name', $name)->first();
        if ($existente !== null) {
            return $existente;
        }

        $exercicio = GymExercise::create(['name' => $name, 'muscle_group' => $group, 'measure_type' => $type]);

        if ($sugerirAoCatalogo) {
            GymExerciseSuggestion::record($name, $group, $type);
        }

        return $exercicio;
    }

    public function findOrCreateEquipment(?string $name): ?GymEquipment
    {
        $name = $name === null ? '' : trim($name);

        if ($name === '') {
            return null;
        }

        return GymEquipment::query()->where('name', $name)->first()
            ?? GymEquipment::create(['name' => $name]);
    }

    /** @param  array<string, mixed>  $meta  campos target_*, rest_seconds, default_equipment_id, notes */
    public function addExercise(GymWorkout $workout, GymExercise $exercise, array $meta): GymWorkoutExercise
    {
        $proxima = (int) GymWorkoutExercise::query()->where('workout_id', $workout->id)->max('position') + 1;

        return GymWorkoutExercise::create($this->sanitize($exercise, $meta) + [
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
            'position' => $proxima,
        ]);
    }

    /**
     * Troca (ou remove) a foto do exercício. A foto é do CATÁLOGO, não do
     * item do treino: vale em todo treino e toda fase que usarem esse
     * exercício. Sempre apaga o arquivo antigo do disco — nunca deixa
     * lixo órfão pra trás.
     *
     * `$file2` é o segundo quadro (início/fim do movimento) — só o vínculo
     * automático de referência (cerne:gym-link-images) usa; o upload feito
     * à mão na tela manda só `$file`. `$remove` apaga os dois quadros.
     */
    public function setExerciseImage(GymExercise $exercise, ?UploadedFile $file, ?UploadedFile $file2 = null, bool $remove = false): GymExercise
    {
        // Trocar só o quadro 1 (upload manual) descarta um quadro 2 que
        // tivesse ficado de um vínculo automático anterior — os dois
        // quadros são um PAR, um quadro 1 novo sem par não pode ficar
        // animando contra um quadro 2 de outra foto.
        $exercise->update([
            'image_path' => $this->storeImage($exercise, $exercise->image_path, $file, $remove),
            'image_path_2' => $this->storeImage($exercise, $exercise->image_path_2, $file2, $remove || ($file !== null && $file2 === null)),
        ]);

        return $exercise;
    }

    /**
     * Um quadro da foto: apaga o antigo (se está sendo trocado ou removido)
     * e grava o novo, se veio um. `$dropExisting` cobre o caso do segundo
     * quadro sumir quando SÓ o primeiro é trocado (uma foto nova de um
     * upload manual não tem par — não faz sentido manter o quadro 2 antigo
     * pendurado nela).
     */
    private function storeImage(GymExercise $exercise, ?string $atual, ?UploadedFile $file, bool $dropExisting): ?string
    {
        $disco = Storage::disk(config('cerne.gym_images.disk'));

        if ($atual !== null && ($file !== null || $dropExisting)) {
            $disco->delete($atual);
        }

        if ($file === null) {
            return $dropExisting ? null : $atual;
        }

        // Nome aleatório: não expõe o nome original do arquivo e não colide
        // entre pessoas diferentes, mesmo com o mesmo nome de exercício.
        return $file->storeAs(
            config('cerne.gym_images.path').'/'.$exercise->member_id,
            Str::uuid().'.'.$file->extension(),
            config('cerne.gym_images.disk'),
        );
    }

    /**
     * Copia a foto do CATÁLOGO compartilhado pro exercício recém-criado —
     * só quando ele ainda não tem foto própria (nunca sobrescreve o que
     * a pessoa já tinha) e o catálogo tem o que copiar. É cópia de
     * verdade no disco: a foto da pessoa fica independente da entrada do
     * catálogo dali em diante (editar/remover uma nunca afeta a outra).
     */
    public function copyCatalogImage(GymExercise $exercise, GymExerciseCatalog $catalogo): GymExercise
    {
        if ($exercise->image_path !== null || $catalogo->image_path === null) {
            return $exercise;
        }

        $disco = Storage::disk(config('cerne.gym_images.disk'));
        $pasta = config('cerne.gym_images.path').'/'.$exercise->member_id;

        $caminho1 = $pasta.'/'.Str::uuid().'.'.pathinfo($catalogo->image_path, PATHINFO_EXTENSION);
        $disco->copy($catalogo->image_path, $caminho1);

        $caminho2 = null;
        if ($catalogo->image_path_2 !== null) {
            $caminho2 = $pasta.'/'.Str::uuid().'.'.pathinfo($catalogo->image_path_2, PATHINFO_EXTENSION);
            $disco->copy($catalogo->image_path_2, $caminho2);
        }

        $exercise->update(['image_path' => $caminho1, 'image_path_2' => $caminho2]);

        return $exercise;
    }

    /** @param  array<string, mixed>  $meta */
    public function updateExercise(GymWorkoutExercise $item, array $meta): GymWorkoutExercise
    {
        $item->update($this->sanitize($item->exercise, $meta));

        return $item;
    }

    public function removeExercise(GymWorkoutExercise $item): void
    {
        DB::transaction(function () use ($item): void {
            $workoutId = $item->workout_id;
            $item->delete();
            $this->renumber(GymWorkoutExercise::query()->where('workout_id', $workoutId));
        });
    }

    public function moveExercise(GymWorkoutExercise $item, int $direction): void
    {
        $this->move($item, GymWorkoutExercise::query()->where('workout_id', $item->workout_id), $direction);
    }

    /**
     * Próximo treino da rotação: o seguinte ao do último treino CONCLUÍDO,
     * voltando ao primeiro depois do último. Sem nenhum concluído, o
     * primeiro.
     */
    public function nextWorkout(GymPlan $plan): ?GymWorkout
    {
        $treinos = $plan->workouts()->get();

        if ($treinos->isEmpty()) {
            return null;
        }

        $ultima = GymSession::query()
            ->whereIn('workout_id', $treinos->pluck('id'))
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();

        if ($ultima === null) {
            return $treinos->first();
        }

        $indice = $treinos->search(fn (GymWorkout $t) => $t->id === $ultima->workout_id);

        return $treinos[(($indice === false ? -1 : $indice) + 1) % $treinos->count()];
    }

    /**
     * Salva o rascunho do treino automático (já revisado pela pessoa) como uma fase nova: encerra a ativa, cria os treinos e
     * os exercícios, que passam a existir para ela com a foto do catálogo. Tudo ou nada.
     *
     * @param  array{name: string, workouts: list<array{name: string, focus: ?string, exercises: list<array<string, mixed>>}>}  $rascunho
     */
    public function createPlanFromDraft(array $rascunho): GymPlan
    {
        return DB::transaction(function () use ($rascunho): GymPlan {
            $plano = $this->createPlan(mb_substr($rascunho['name'], 0, 80));

            // As respostas ficam na fase: é com elas que o Cerne sabe quando avisar que é hora de trocar e o que perguntar na próxima.
            if (isset($rascunho['input'])) {
                $i = $rascunho['input'];
                $plano->update([
                    'goal' => GymGoal::tryFrom((string) ($i['goal'] ?? '')), 'level' => GymLevel::tryFrom((string) ($i['level'] ?? '')),
                    'days_per_week' => $i['days'] ?? null, 'session_minutes' => $i['minutes'] ?? null,
                    'equipment_access' => GymEquipmentAccess::tryFrom((string) ($i['access'] ?? '')), 'restrictions' => $i['restrictions'] ?? [],
                    'sport' => GymSport::tryFrom((string) ($i['sport'] ?? '')), 'sport_days' => $i['sport_days'] ?? null, 'season' => GymSeason::tryFrom((string) ($i['season'] ?? '')),
                ]);
            }

            // As orientações do esporte (cuidados, limite de dias) viram o texto "Orientações da fase" da Academia.
            if (! empty($rascunho['notes'])) {
                $plano->update(['notes' => mb_substr((string) $rascunho['notes'], 0, 4000)]);
            }

            foreach ($rascunho['workouts'] as $w) {
                if ($w['exercises'] === []) {
                    continue;
                }

                $treino = $this->addWorkout($plano, $w['name'], $w['focus'] ?? null);

                foreach ($w['exercises'] as $e) {
                    [$exercicio, $equipamento] = $this->resolveSwapChoice('catalog:'.$e['catalog_id']);

                    $this->addExercise($treino, $exercicio, [
                        'target_sets' => $e['sets'], 'target_reps_min' => $e['reps_min'], 'target_reps_max' => $e['reps_max'],
                        'target_duration_seconds' => $e['duration'], 'rest_seconds' => $e['rest'],
                        'default_equipment_id' => $this->findOrCreateEquipment($equipamento !== null ? mb_substr($equipamento, 0, 80) : null)?->id,
                        'notes' => $e['notes'] ?? null,
                    ]);
                }
            }

            return $plano;
        });
    }

    /** Define (ou corrige) o objetivo e o nível de uma fase montada à mão, para o aviso de troca saber de que prazo falar. */
    public function setPlanGoal(GymPlan $plano, ?GymGoal $objetivo, ?GymLevel $nivel): GymPlan
    {
        $plano->update(['goal' => $objetivo, 'level' => $nivel]);

        return $plano;
    }

    /**
     * Nomes (normalizados) dos exercícios de uma fase, para o treino novo poder evitar repeti-los.
     *
     * @return list<string>
     */
    public function exerciseNamesOf(GymPlan $plano): array
    {
        return GymWorkoutExercise::query()->whereIn('workout_id', $plano->workouts()->select('id'))->with('exercise:id,name')->get()
            ->map(fn (GymWorkoutExercise $i) => \App\Support\GymExerciseClassifier::normalize($i->exercise->name))->unique()->values()->all();
    }

    // --- Troca de exercício ------------------------------------------------------------------------------------------

    /**
     * Opções para trocar o exercício de um item do treino: os que a pessoa já usa e os do catálogo, do mesmo grupo muscular
     * (de qualquer grupo, se ela procurar por nome), sem os que já estão neste treino. Os do mesmo tipo de medida vêm primeiro
     * (carga por carga, tempo por tempo) e, entre iguais, os que ela já conhece.
     *
     * @return list<array{key: string, name: string, group: string, equipment: ?string, photo: bool, own: bool}>
     */
    public function swapOptions(GymWorkoutExercise $item, string $busca = '', bool $todosGrupos = false, int $limite = 40): array
    {
        $atual = $item->exercise;
        $busca = GymExerciseCatalog::normalizeName($busca);
        $todos = $todosGrupos || $busca !== '';
        $excluidos = GymWorkoutExercise::query()->where('workout_id', $item->workout_id)->with('exercise')->get()
            ->map(fn (GymWorkoutExercise $i) => GymExerciseCatalog::normalizeName($i->exercise->name))
            ->all();

        $proprios = GymExercise::query()->where('id', '!=', $atual->id)
            ->when(! $todos, fn ($q) => $q->where('muscle_group', $atual->muscle_group->value))
            ->get()
            ->map(fn (GymExercise $e) => [
                'key' => 'own:'.$e->id, 'name' => $e->name, 'group' => $e->muscle_group->label(), 'equipment' => null,
                'photo' => $e->image_path !== null, 'own' => true, 'same_type' => $e->measure_type === $atual->measure_type,
            ]);

        $nomesProprios = $proprios->map(fn (array $o) => GymExerciseCatalog::normalizeName($o['name']))->all();

        $catalogo = GymExerciseCatalog::query()->active()->withoutInstructions()
            ->when(! $todos, fn ($q) => $q->where('muscle_group', $atual->muscle_group->value))
            ->get()
            ->reject(fn (GymExerciseCatalog $c) => in_array(GymExerciseCatalog::normalizeName($c->name), $nomesProprios, true))
            ->map(fn (GymExerciseCatalog $c) => [
                'key' => 'catalog:'.$c->id, 'name' => $c->name, 'group' => $c->muscle_group->label(), 'equipment' => $c->equipment_hint,
                'photo' => $c->image_path !== null, 'own' => false, 'same_type' => $c->measure_type === $atual->measure_type,
            ]);

        return $proprios->concat($catalogo)
            ->reject(fn (array $o) => in_array(GymExerciseCatalog::normalizeName($o['name']), $excluidos, true))
            ->when($busca !== '', fn ($c) => $c->filter(fn (array $o) => str_contains(GymExerciseCatalog::normalizeName($o['name'].' '.$o['equipment']), $busca)))
            ->sortBy([['same_type', 'desc'], ['own', 'desc'], ['name', 'asc']])
            ->take($limite)
            ->map(fn (array $o) => collect($o)->except('same_type')->all())
            ->values()
            ->all();
    }

    /**
     * Resolve a opção escolhida na lista de troca: um exercício que a pessoa já tem ou um do catálogo (que passa a existir
     * para ela, com a foto copiada). Devolve também o equipamento sugerido pelo catálogo.
     *
     * @return array{0: GymExercise, 1: ?string}
     */
    public function resolveSwapChoice(string $chave): array
    {
        [$tipo, $id] = array_pad(explode(':', $chave, 2), 2, null);

        if ($tipo === 'own' && $id !== null) {
            return [GymExercise::query()->findOrFail($id), null];
        }

        if ($tipo === 'catalog' && $id !== null) {
            $catalogo = GymExerciseCatalog::query()->active()->findOrFail($id);
            $exercicio = $this->findOrCreateExercise($catalogo->name, $catalogo->muscle_group, $catalogo->measure_type);
            $this->copyCatalogImage($exercicio, $catalogo);

            return [$exercicio, $catalogo->equipment_hint];
        }

        throw new DomainException('Escolha um exercício da lista.');
    }

    /**
     * Troca o exercício de um item do treino e MANTÉM o treino assim para as próximas vezes. A meta (séries, repetições, pausa,
     * observação e equipamento) é a do exercício NOVO (ver prescriptionFor), não a do que saiu. O histórico não muda, porque cada
     * série gravada guarda o exercício que foi feito.
     *
     * Treino em andamento com série já feita no exercício atual não troca: ela ficaria sem lugar na tela.
     */
    public function swapExercise(GymWorkoutExercise $item, GymExercise $novo, ?string $equipamento = null): GymWorkoutExercise
    {
        if ($novo->id === $item->exercise_id) {
            return $item;
        }

        $emAndamento = GymSession::query()->inProgress()->where('workout_id', $item->workout_id)->first();

        if ($emAndamento !== null && GymSetLog::query()->where('session_id', $emAndamento->id)->where('exercise_id', $item->exercise_id)->exists()) {
            throw new DomainException('Este exercício já tem séries no treino em andamento. Termine ou descarte o treino antes de trocar.');
        }

        $duplicado = GymWorkoutExercise::query()->where('workout_id', $item->workout_id)->where('exercise_id', $novo->id)->where('id', '!=', $item->id)->exists();

        if ($duplicado) {
            throw new DomainException('Este exercício já está neste treino.');
        }

        $meta = $this->prescriptionFor($item, $novo, $equipamento);

        $item->update($this->sanitize($novo, $meta) + ['exercise_id' => $novo->id]);
        $item->setRelation('exercise', $novo);

        return $item;
    }

    /**
     * A meta do exercício que entra no lugar de outro. Vale, nesta ordem: o que a pessoa já tem para ele em outro treino ou fase (a
     * própria meta e a observação dela); o que ela já fez nele (séries e repetições do último treino finalizado); e, sem histórico,
     * a meta que o treino automático daria a esse exercício para o objetivo e o nível da fase. A observação do exercício que saiu
     * nunca vai junto, só o aquecimento do primeiro exercício do dia, que é do espaço e não do exercício.
     *
     * @return array<string, mixed>  campos de GymWorkoutExercise: target_*, rest_seconds, default_equipment_id e notes
     */
    public function prescriptionFor(GymWorkoutExercise $item, GymExercise $novo, ?string $equipamento = null): array
    {
        $plano = GymWorkout::query()->with('plan')->find($item->workout_id)?->plan;
        $aquecimento = $item->notes === GymWorkoutGenerator::WARM_UP ? GymWorkoutGenerator::WARM_UP : null;

        $anterior = GymWorkoutExercise::query()->where('exercise_id', $novo->id)->where('id', '!=', $item->id)->latest('updated_at')->first();

        if ($anterior !== null) {
            return [
                'target_sets' => $anterior->target_sets,
                'target_reps_min' => $anterior->target_reps_min,
                'target_reps_max' => $anterior->target_reps_max,
                'target_duration_seconds' => $anterior->target_duration_seconds,
                'rest_seconds' => $anterior->rest_seconds,
                'default_equipment_id' => $this->findOrCreateEquipment($equipamento)?->id ?? $anterior->default_equipment_id,
                'notes' => $anterior->notes ?? $aquecimento,
            ];
        }

        $base = app(GymWorkoutGenerator::class)->prescriptionFor($novo, $plano);
        $meta = [
            'target_sets' => $base['sets'],
            'target_reps_min' => $base['reps_min'],
            'target_reps_max' => $base['reps_max'],
            'target_duration_seconds' => $base['duration'],
            'rest_seconds' => $base['rest'],
            'default_equipment_id' => $this->findOrCreateEquipment($equipamento)?->id,
            'notes' => $aquecimento ?? $base['notes'],
        ];

        // Já fez esse exercício: o que a pessoa realmente fez vale mais que a regra geral.
        $ultimo = GymSetLog::query()->where('exercise_id', $novo->id)
            ->whereIn('session_id', GymSession::query()->whereNotNull('finished_at')->select('id'))
            ->orderByDesc('completed_at')->first();

        if ($ultimo !== null) {
            $series = GymSetLog::query()->where('session_id', $ultimo->session_id)->where('exercise_id', $novo->id)->get();
            $meta['target_sets'] = max(1, min(6, $series->count()));

            if ($novo->measure_type->usesStopwatch()) {
                $media = (int) round($series->whereNotNull('duration_seconds')->avg('duration_seconds') ?? 0);
                $meta['target_duration_seconds'] = $media > 0 ? $media : $meta['target_duration_seconds'];
            } else {
                $reps = $series->pluck('reps')->filter()->values();
                $meta['target_reps_min'] = $reps->isNotEmpty() ? (int) $reps->min() : $meta['target_reps_min'];
                $meta['target_reps_max'] = $reps->isNotEmpty() ? (int) $reps->max() : $meta['target_reps_max'];
            }

            $meta['default_equipment_id'] ??= $ultimo->equipment_id;
        }

        return $meta;
    }

    /**
     * Exercício medido em tempo (prancha, esteira) usa cronômetro, não
     * pausa entre séries — então não guarda repetições nem descanso.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function sanitize(GymExercise $exercise, array $meta): array
    {
        $timed = $exercise->measure_type->usesStopwatch();

        return [
            'target_sets' => (int) ($meta['target_sets'] ?? 3),
            'target_reps_min' => $timed ? null : ($meta['target_reps_min'] ?? null),
            'target_reps_max' => $timed ? null : ($meta['target_reps_max'] ?? null),
            'target_duration_seconds' => $timed ? ($meta['target_duration_seconds'] ?? null) : null,
            'rest_seconds' => $timed ? null : ($meta['rest_seconds'] ?? null),
            'default_equipment_id' => $meta['default_equipment_id'] ?? null,
            'notes' => isset($meta['notes']) && trim((string) $meta['notes']) !== '' ? trim((string) $meta['notes']) : null,
        ];
    }

    /**
     * Troca `position` com o vizinho. O índice único (pai, position)
     * proíbe dois com o mesmo valor, nem por um instante — daí o 0
     * intermediário (posições reais começam em 1).
     */
    private function move(Model $item, $siblings, int $direction): void
    {
        $vizinho = (clone $siblings)
            ->where('position', $direction < 0 ? '<' : '>', $item->position)
            ->orderBy('position', $direction < 0 ? 'desc' : 'asc')
            ->first();

        if ($vizinho === null) {
            return;
        }

        DB::transaction(function () use ($item, $vizinho): void {
            $a = $item->position;
            $b = $vizinho->position;

            $item->update(['position' => 0]);
            $vizinho->update(['position' => $a]);
            $item->update(['position' => $b]);
        });
    }

    /** Fecha o buraco depois de uma exclusão: 1, 2, 3... em ordem crescente (nunca colide). */
    private function renumber($query): void
    {
        $posicao = 1;

        foreach ($query->orderBy('position')->get() as $linha) {
            if ($linha->position !== $posicao) {
                $linha->update(['position' => $posicao]);
            }
            $posicao++;
        }
    }
}
