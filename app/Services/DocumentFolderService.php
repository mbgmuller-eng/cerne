<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentFolder;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Pastas de Documentos. Tudo roda sob o ProfileContext (BelongsToProfile filtra e
 * carimba o perfil), então uma pasta de outro perfil nem é encontrada.
 *
 * O serviço só mexe em organização: nenhuma operação aqui muda a categoria do
 * documento, e portanto nunca muda quem o enxerga.
 */
class DocumentFolderService
{
    /** Pastas sugeridas para quem ainda não tem nenhuma (os exemplos de quem testou o app). */
    public const SUGERIDAS = ['Propriedades', 'Veículos', 'Família', 'Outros'];

    public function create(string $nome, ?DocumentFolder $pai): DocumentFolder
    {
        $nome = $this->nomeValido($nome);

        if ($pai !== null && $this->depth($pai) >= DocumentFolder::MAX_DEPTH) {
            throw new DomainException('Esta pasta já está no nível mais fundo permitido ('.DocumentFolder::MAX_DEPTH.' níveis).');
        }

        $this->garantirNomeLivre($nome, $pai?->id);

        return DocumentFolder::create(['parent_id' => $pai?->id, 'name' => $nome]);
    }

    /** Renomeia e/ou muda de lugar (`$novoPai` nulo = raiz). */
    public function update(DocumentFolder $pasta, string $nome, ?DocumentFolder $novoPai): DocumentFolder
    {
        $nome = $this->nomeValido($nome);

        if ($novoPai !== null) {
            if ($novoPai->id === $pasta->id || $this->descendantIds($pasta)->contains($novoPai->id)) {
                throw new DomainException('Uma pasta não pode ficar dentro dela mesma nem das suas subpastas.');
            }

            if ($this->depth($novoPai) + 1 + $this->height($pasta) > DocumentFolder::MAX_DEPTH) {
                throw new DomainException('Mover para lá passaria do máximo de '.DocumentFolder::MAX_DEPTH.' níveis de pasta.');
            }
        }

        $this->garantirNomeLivre($nome, $novoPai?->id, $pasta->id);

        $pasta->update(['name' => $nome, 'parent_id' => $novoPai?->id]);

        return $pasta;
    }

    /**
     * Apagar uma pasta NUNCA apaga documento: o conteúdo (documentos e subpastas) sobe para
     * a pasta de cima. Subpasta que ficaria com nome repetido ganha " (2)".
     */
    public function delete(DocumentFolder $pasta): void
    {
        $paiId = $pasta->parent_id;

        Document::query()->where('folder_id', $pasta->id)->update(['folder_id' => $paiId]);

        $irmaos = DocumentFolder::query()->where('parent_id', $paiId)->where('id', '!=', $pasta->id)->pluck('name')
            ->map(fn (string $n) => DocumentFolder::normalizeName($n))->all();

        foreach (DocumentFolder::query()->where('parent_id', $pasta->id)->get() as $filha) {
            $nome = $filha->name;

            while (in_array(DocumentFolder::normalizeName($nome), $irmaos, true)) {
                $nome = $this->proximoNome($nome);
            }

            $irmaos[] = DocumentFolder::normalizeName($nome);
            $filha->update(['parent_id' => $paiId, 'name' => $nome]);
        }

        $pasta->delete();
    }

    /** Cria as pastas sugeridas se o perfil ainda não tem nenhuma. @return int quantas foram criadas */
    public function createSuggested(): int
    {
        if (DocumentFolder::query()->exists()) {
            return 0;
        }

        foreach (self::SUGERIDAS as $nome) {
            DocumentFolder::create(['parent_id' => null, 'name' => $nome]);
        }

        return count(self::SUGERIDAS);
    }

    /** Da raiz até a pasta (inclusive), para o caminho no topo da tela. @return Collection<int, DocumentFolder> */
    public function ancestors(DocumentFolder $pasta): Collection
    {
        $caminho = collect([$pasta]);
        $visitadas = [$pasta->id];

        while ($pasta->parent_id !== null && ! in_array($pasta->parent_id, $visitadas, true)) {
            $pasta = DocumentFolder::query()->find($pasta->parent_id);

            if ($pasta === null) {
                break;
            }

            $visitadas[] = $pasta->id;
            $caminho->prepend($pasta);
        }

        return $caminho;
    }

    /** Todas as pastas em ordem de árvore, com o nível, para o seletor com recuo. @return Collection<int, array{pasta: DocumentFolder, nivel: int, caminho: string}> */
    public function flatTree(?string $excluirComFilhas = null): Collection
    {
        $todas = DocumentFolder::query()->orderBy('name')->get()->groupBy(fn (DocumentFolder $p) => $p->parent_id ?? '');
        $lista = collect();

        $descer = function (string $paiId, int $nivel, string $prefixo) use (&$descer, $todas, $lista, $excluirComFilhas): void {
            foreach ($todas->get($paiId, collect()) as $pasta) {
                if ($pasta->id === $excluirComFilhas) {
                    continue;
                }

                $caminho = $prefixo === '' ? $pasta->name : $prefixo.' › '.$pasta->name;
                $lista->push(['pasta' => $pasta, 'nivel' => $nivel, 'caminho' => $caminho]);
                $descer($pasta->id, $nivel + 1, $caminho);
            }
        };

        $descer('', 0, '');

        return $lista;
    }

    /** @return Collection<int, string> ids de todas as pastas abaixo desta */
    public function descendantIds(DocumentFolder $pasta): Collection
    {
        $porPai = DocumentFolder::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = collect();
        $fila = [$pasta->id];

        while ($fila !== []) {
            $atual = array_shift($fila);

            foreach ($porPai->get($atual, collect()) as $filha) {
                $ids->push($filha->id);
                $fila[] = $filha->id;
            }
        }

        return $ids;
    }

    /** Nível da pasta (raiz = 1). */
    public function depth(DocumentFolder $pasta): int
    {
        return $this->ancestors($pasta)->count();
    }

    /** Quantos níveis existem de BAIXO da pasta, contando ela (sem subpastas = 1). */
    private function height(DocumentFolder $pasta): int
    {
        $porPai = DocumentFolder::query()->get(['id', 'parent_id'])->groupBy('parent_id');

        $altura = function (string $id) use (&$altura, $porPai): int {
            $filhas = $porPai->get($id, collect());

            return 1 + ($filhas->isEmpty() ? 0 : $filhas->map(fn ($f) => $altura($f->id))->max());
        };

        return $altura($pasta->id);
    }

    private function nomeValido(string $nome): string
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        if ($nome === '') {
            throw new DomainException('Dê um nome para a pasta.');
        }

        if (mb_strlen($nome) > 80) {
            throw new DomainException('O nome da pasta pode ter no máximo 80 caracteres.');
        }

        return $nome;
    }

    private function garantirNomeLivre(string $nome, ?string $paiId, ?string $ignorarId = null): void
    {
        $existe = DocumentFolder::query()
            ->where('parent_id', $paiId)
            ->when($ignorarId !== null, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->pluck('name')
            ->contains(fn (string $outro) => DocumentFolder::normalizeName($outro) === DocumentFolder::normalizeName($nome));

        if ($existe) {
            throw new DomainException('Já existe uma pasta com esse nome aqui.');
        }
    }

    private function proximoNome(string $nome): string
    {
        return preg_match('/^(.*) \((\d+)\)$/u', $nome, $m) === 1 ? $m[1].' ('.($m[2] + 1).')' : $nome.' (2)';
    }
}
