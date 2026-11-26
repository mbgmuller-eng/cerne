<?php

namespace App\Services;

use App\Models\HealthAppointment;
use App\Models\HealthExamReport;
use App\Models\HealthExamRequest;
use App\Models\HealthFollowup;
use App\Models\HealthMedication;
use App\Models\HealthProfessional;
use App\Models\HealthProfessionalLink;
use App\Models\ProfileMember;
use App\Support\Health\ExamCatalog;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Médicos e profissionais guardados. Cada um é ligado às pessoas que atende; quem olha só mexe nas ligações das pessoas que enxerga (as outras
 * ficam como estão). Os nomes são cifrados, então busca, ordem e comparação são feitas em PHP: são poucos por família.
 *
 * Os registros (consulta, pedido, laudo, programa, remédio) guardam o nome como foi escrito; `professional_id` os liga à ficha.
 */
class HealthProfessionalService
{
    /** Onde o nome de um profissional aparece: model => [coluna do nome, como contar na tela (singular, plural)]. */
    private const SOURCES = [
        HealthAppointment::class => ['professional_name', 'consulta', 'consultas'],
        HealthExamRequest::class => ['requested_by', 'pedido de exame', 'pedidos de exame'],
        HealthExamReport::class => ['doctor', 'laudo', 'laudos'],
        HealthFollowup::class => ['guided_by', 'programa de atividades', 'programas de atividades'],
        HealthMedication::class => ['prescriber', 'remédio', 'remédios'],
    ];

    private const FIELDS = ['specialty', 'registry', 'place', 'address', 'phone', 'notes'];

    /** Todos os que quem olha enxerga: os fixos primeiro, depois por nome. @return Collection<int, HealthProfessional> */
    public function visible(): Collection
    {
        return HealthProfessional::query()->with(['links.member', 'createdByUser'])->get()
            ->sortBy(fn (HealthProfessional $p) => ($p->is_favorite ? '0' : '1').ExamCatalog::normalize($p->name))
            ->values();
    }

    /** @return Collection<int, HealthProfessional> os que atendem esta pessoa */
    public function forPerson(string $membroId): Collection
    {
        return $this->visible()->filter(fn (HealthProfessional $p) => $p->links->contains('member_id', $membroId))->values();
    }

    /**
     * Cria ou altera. `$pessoas` são as marcadas entre as que quem olha enxerga: as ligações com quem ele não vê ficam intactas.
     *
     * @param  array{name: string, specialty?: ?string, registry?: ?string, place?: ?string, address?: ?string, phone?: ?string, notes?: ?string, is_favorite?: bool}  $dados
     * @param  list<string>  $pessoas
     */
    public function save(?HealthProfessional $profissional, array $dados, array $pessoas, ?ProfileMember $autor): HealthProfessional
    {
        $nome = mb_substr(trim($dados['name']), 0, 120);

        if ($nome === '') {
            throw new InvalidArgumentException('Informe o nome do profissional.');
        }

        $visiveis = ProfileMember::query()->visibleHere()->pluck('id')->all();
        $pessoas = array_values(array_unique($pessoas));

        if ($pessoas === [] || array_diff($pessoas, $visiveis) !== []) {
            throw new InvalidArgumentException('Marque quem esse profissional atende.');
        }

        if ($profissional !== null) {
            $this->assertCanEdit($profissional);
        }

        $campos = ['name' => $nome, 'is_favorite' => (bool) ($dados['is_favorite'] ?? false)];

        foreach (self::FIELDS as $campo) {
            $valor = trim((string) ($dados[$campo] ?? ''));
            $campos[$campo] = $valor === '' ? null : mb_substr($valor, 0, $campo === 'notes' ? 2000 : 200);
        }

        $salvo = DB::transaction(function () use ($profissional, $campos, $pessoas, $autor): HealthProfessional {
            if ($profissional === null) {
                $profissional = HealthProfessional::create($campos + ['created_by_member_id' => $autor?->id]);
                $atuais = collect();
            } else {
                $profissional->update($campos);
                $atuais = HealthProfessionalLink::query()->where('professional_id', $profissional->id)->get();
            }

            foreach (array_diff($pessoas, $atuais->pluck('member_id')->all()) as $membroId) {
                $this->link($profissional->id, $membroId, $autor);
            }

            // Só sai a ligação que quem olha enxerga e desmarcou (quem edita sem ter registrado é barrado pela trava de quem acompanha).
            $atuais->reject(fn (HealthProfessionalLink $l) => in_array($l->member_id, $pessoas, true))->each->delete();

            return $profissional;
        });

        $this->linkRecords($salvo);

        return $salvo;
    }

    public function toggleFavorite(HealthProfessional $profissional): void
    {
        $this->assertCanEdit($profissional);
        $profissional->update(['is_favorite' => ! $profissional->is_favorite]);
    }

    /**
     * Tira da lista de quem olha: saem as ligações que ele enxerga. Se ninguém mais o usa, a ficha é apagada; se ainda atende alguém que ele não
     * vê, continua para essa pessoa. Consultas e pedidos ficam, com o nome escrito.
     */
    public function remove(HealthProfessional $profissional): void
    {
        DB::transaction(function () use ($profissional): void {
            HealthProfessionalLink::query()->where('professional_id', $profissional->id)->get()->each->delete();

            // Sem os escopos de propósito: precisa saber se sobrou ligação com quem quem olha não vê.
            if (! HealthProfessionalLink::withoutGlobalScopes()->where('professional_id', $profissional->id)->exists()) {
                $contexto = app(ProfileContext::class);
                abort_unless($contexto->canWriteAsCaregiver(), 403);
                // Sem ligação o escopo já não o enxerga: apaga pela chave, com a permissão conferida ao tirar as ligações.
                HealthProfessional::withoutGlobalScopes()->where('profile_id', $contexto->profileId())->whereKey($profissional->id)->delete();
            }
        });
    }

    /**
     * O que um formulário de consulta, pedido, laudo, programa ou remédio deve gravar em `professional_id`: o escolhido na lista, o que já existe
     * com o mesmo nome, um novo (se a pessoa pediu para guardar) ou nenhum. O id vem do navegador: só vale o que quem olha enxerga.
     *
     * @param  array{specialty?: ?string, place?: ?string, address?: ?string, phone?: ?string}  $extras  usados só ao criar
     */
    public function resolve(string $membroId, ?string $escolhido, ?string $nome, bool $guardar, array $extras, ?ProfileMember $autor): ?string
    {
        $nome = trim((string) $nome);

        if ($nome === '') {
            return null;
        }

        $chave = ExamCatalog::normalize($nome);
        $todos = $this->visible();
        $daPessoa = $todos->filter(fn (HealthProfessional $p) => $p->links->contains('member_id', $membroId));
        $achado = $daPessoa->first(fn (HealthProfessional $p) => $p->id === $escolhido && ExamCatalog::normalize($p->name) === $chave)
            ?? $daPessoa->first(fn (HealthProfessional $p) => ExamCatalog::normalize($p->name) === $chave);

        if ($achado !== null) {
            return $achado->id;
        }

        if (! $guardar) {
            return null;
        }

        // Já guardado para outra pessoa que quem olha enxerga: liga, em vez de criar uma ficha repetida.
        $deOutra = $todos->first(fn (HealthProfessional $p) => ExamCatalog::normalize($p->name) === $chave);

        if ($deOutra !== null) {
            $this->link($deOutra->id, $membroId, $autor);
            $this->linkRecords($deOutra);

            return $deOutra->id;
        }

        return $this->save(null, ['name' => $nome] + $extras, [$membroId], $autor)->id;
    }

    /**
     * Nomes já escritos nos registros que ainda não estão guardados para aquela pessoa. É só sugestão: nada é guardado sem a pessoa pedir.
     *
     * @return list<array{key: string, name: string, member_id: string, person: string, where: string}>
     */
    public function typedNames(): array
    {
        $pessoas = ProfileMember::query()->visibleHere()->get()->keyBy('id');
        $guardados = [];

        foreach ($this->visible() as $p) {
            foreach ($p->links as $l) {
                $guardados[$l->member_id.'|'.ExamCatalog::normalize($p->name)] = true;
            }
        }

        $achados = [];

        foreach (self::SOURCES as $model => [$coluna]) {
            foreach ($model::query()->whereNull('professional_id')->whereNotNull($coluna)->latest()->limit(300)->get() as $registro) {
                $nome = trim((string) $registro->{$coluna});
                $chave = $registro->member_id.'|'.ExamCatalog::normalize($nome);

                if ($nome === '' || isset($guardados[$chave]) || ! $pessoas->has($registro->member_id)) {
                    continue;
                }

                $achados[$chave] ??= ['key' => md5($chave), 'name' => $nome, 'member_id' => $registro->member_id, 'person' => $pessoas[$registro->member_id]->name, 'counts' => []];
                $achados[$chave]['counts'][$model] = ($achados[$chave]['counts'][$model] ?? 0) + 1;
            }
        }

        return collect($achados)->map(function (array $a): array {
            $partes = [];

            foreach ($a['counts'] as $model => $quantos) {
                $partes[] = $quantos.' '.self::SOURCES[$model][$quantos === 1 ? 1 : 2];
            }

            return ['key' => $a['key'], 'name' => $a['name'], 'member_id' => $a['member_id'], 'person' => $a['person'], 'where' => implode(', ', $partes)];
        })->sortBy('name')->values()->all();
    }

    /**
     * O que está ligado à ficha, do mais recente para o mais antigo. Cada registro passa pelo escopo de quem olha.
     *
     * @return list<array{date: ?CarbonImmutable, label: string, title: string, person: string, url: string}>
     */
    public function history(HealthProfessional $profissional): array
    {
        $linhas = [];
        $linha = fn ($data, string $rotulo, string $titulo, $registro, string $url): array => [
            'date' => $data === null ? null : CarbonImmutable::parse($data->toDateTimeString()), 'label' => $rotulo, 'title' => $titulo,
            'person' => (string) $registro->member?->name, 'url' => $url,
        ];

        foreach (HealthAppointment::query()->where('professional_id', $profissional->id)->with('member')->get() as $r) {
            $linhas[] = $linha($r->scheduled_at, $r->kind->label(), $r->title, $r, route('health.appointments.index'));
        }

        foreach (HealthExamRequest::query()->where('professional_id', $profissional->id)->with('member')->get() as $r) {
            $linhas[] = $linha($r->requested_on, 'Pedido de exames', $r->title, $r, route('health.exams.show', $r->id));
        }

        foreach (HealthExamReport::query()->where('professional_id', $profissional->id)->with(['member', 'request'])->get() as $r) {
            $linhas[] = $linha($r->performed_on, 'Laudo', $r->exam_name ?? $r->request?->title ?? 'Resultado de exames', $r, $r->request_id ? route('health.exams.show', $r->request_id) : route('health.exams.results'));
        }

        foreach (HealthFollowup::query()->where('professional_id', $profissional->id)->with('member')->get() as $r) {
            $linhas[] = $linha($r->guided_on ?? $r->started_on, 'Orientou o programa', $r->title, $r, route('health.followups.show', $r->id));
        }

        foreach (HealthMedication::query()->where('professional_id', $profissional->id)->with('member')->get() as $r) {
            $linhas[] = $linha($r->created_at, 'Receitou', $r->name, $r, route('health.card.index'));
        }

        return collect($linhas)->sortByDesc('date')->values()->all();
    }

    /**
     * Liga à ficha os registros já escritos com exatamente este nome, das pessoas que ela atende e que quem olha pode alterar. Atualiza só a ligação
     * (sem eventos do model): não é mudança no registro, e não avisa ninguém.
     */
    private function linkRecords(HealthProfessional $profissional): void
    {
        $chave = ExamCatalog::normalize($profissional->name);
        $pessoas = HealthProfessionalLink::query()->where('professional_id', $profissional->id)->pluck('member_id')
            ->filter(fn (string $id) => app(ProfileContext::class)->canWriteAsCaregiver($id))->all();

        if ($pessoas === []) {
            return;
        }

        foreach (self::SOURCES as $model => [$coluna]) {
            $ids = $model::query()->whereIn('member_id', $pessoas)->whereNull('professional_id')->whereNotNull($coluna)->get()
                ->filter(fn ($r) => ExamCatalog::normalize((string) $r->{$coluna}) === $chave)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $model::query()->whereKey($ids->all())->toBase()->update(['professional_id' => $profissional->id]);
            }
        }
    }

    private function link(string $profissionalId, string $membroId, ?ProfileMember $autor): void
    {
        try {
            HealthProfessionalLink::create(['professional_id' => $profissionalId, 'member_id' => $membroId, 'created_by_member_id' => $autor?->id]);
        } catch (UniqueConstraintViolationException) {
            // Já ligado (outra aba): o índice único é a proteção.
        }
    }

    /** Quem acompanha só altera a ficha de quem atende alguma pessoa em que ele tem acesso de edição. */
    private function assertCanEdit(HealthProfessional $profissional): void
    {
        $contexto = app(ProfileContext::class);

        abort_unless(
            HealthProfessionalLink::query()->where('professional_id', $profissional->id)->pluck('member_id')->contains(fn (string $id) => $contexto->canWriteAsCaregiver($id)),
            403,
        );
    }
}
