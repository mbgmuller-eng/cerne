<?php

namespace App\Services;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\ProfileMember;
use App\Support\ProfileContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Área de Documentos — upload e remoção. Visibilidade não é decidida aqui
 * (ver DocumentVisibilityScope); este serviço só grava o que a tela pediu.
 */
class DocumentService
{
    /** @param  array<string, mixed>  $dados  category, title, member_id, insurance_policy_id, expires_on, visible_to_professional */
    public function upload(UploadedFile $arquivo, array $dados, ProfileMember $autor): Document
    {
        $caminho = $arquivo->store(
            config('cerne.document_vault.path').'/'.$autor->profile_id,
            config('cerne.document_vault.disk'),
        );

        return $this->registrar(
            $dados,
            $caminho,
            $arquivo->getClientOriginalName(),
            $arquivo->getMimeType() ?? $arquivo->getClientMimeType(),
            $arquivo->getSize(),
            $autor,
        );
    }

    /**
     * Guarda em Documentos um PDF que já está em disco (ex.: a leitura de apólice por IA, que sobe
     * para a pasta de importação). Copia, não move: o mesmo arquivo pode ficar ligado a mais de uma
     * apólice, cada uma com a sua cópia.
     *
     * @param  array<string, mixed>  $dados  category, title, member_id, insurance_policy_id, expires_on
     */
    public function adopt(string $disco, string $caminhoOrigem, string $nomeOriginal, array $dados, ?ProfileMember $autor = null): Document
    {
        $perfilId = app(ProfileContext::class)->profileId();
        $cofre = Storage::disk(config('cerne.document_vault.disk'));
        $destino = config('cerne.document_vault.path').'/'.$perfilId.'/'.Str::random(40).'.pdf';

        $cofre->put($destino, Storage::disk($disco)->readStream($caminhoOrigem));

        return $this->registrar($dados, $destino, $nomeOriginal, 'application/pdf', $cofre->size($destino), $autor);
    }

    /** @param  array<string, mixed>  $dados */
    private function registrar(array $dados, string $caminho, string $nomeOriginal, ?string $mime, int $tamanho, ?ProfileMember $autor): Document
    {
        $categoria = DocumentCategory::from($dados['category']);

        return Document::create([
            'member_id' => $dados['member_id'] ?? null,
            'folder_id' => $this->pastaDoPerfil($dados['folder_id'] ?? null),
            'category' => $categoria,
            // Só apólice usa o vínculo; toda outra categoria ignora o campo,
            // mesmo se vier preenchido por engano.
            'insurance_policy_id' => $categoria === DocumentCategory::InsurancePolicy ? ($dados['insurance_policy_id'] ?? null) : null,
            'title' => trim($dados['title']),
            'original_filename' => $nomeOriginal,
            'storage_path' => $caminho,
            'mime_type' => $mime,
            'size_bytes' => $tamanho,
            'expires_on' => $dados['expires_on'] ?? null,
            // Só tem efeito pra category=other (ver DocumentVisibilityScope);
            // gravar mesmo assim não abre brecha nenhuma nas outras categorias.
            'visible_to_professional' => $categoria === DocumentCategory::Other && (bool) ($dados['visible_to_professional'] ?? false),
            'created_by_member_id' => $autor?->id,
        ]);
    }

    /**
     * Só metadado — trocar o arquivo em si é upload novo (apagar e subir de
     * novo), não edição. Cobre o caso comum: título errado, categoria
     * errada, ou apólice/pessoa que mudou depois.
     *
     * @param  array<string, mixed>  $dados  category, title, member_id, insurance_policy_id, expires_on, visible_to_professional
     */
    public function update(Document $documento, array $dados): Document
    {
        $categoria = DocumentCategory::from($dados['category']);

        $documento->update([
            'member_id' => $dados['member_id'] ?? null,
            'folder_id' => $this->pastaDoPerfil($dados['folder_id'] ?? null),
            'category' => $categoria,
            'insurance_policy_id' => $categoria === DocumentCategory::InsurancePolicy ? ($dados['insurance_policy_id'] ?? null) : null,
            'title' => trim($dados['title']),
            'expires_on' => $dados['expires_on'] ?? null,
            'visible_to_professional' => $categoria === DocumentCategory::Other && (bool) ($dados['visible_to_professional'] ?? false),
        ]);

        return $documento;
    }

    /**
     * Só aceita uma pasta que exista NESTE perfil (BelongsToProfile filtra); qualquer outro id
     * vira "sem pasta" em vez de gravar uma referência para a pasta de outra pessoa.
     */
    private function pastaDoPerfil(?string $folderId): ?string
    {
        if ($folderId === null || $folderId === '') {
            return null;
        }

        return DocumentFolder::query()->whereKey($folderId)->value('id');
    }

    public function delete(Document $documento): void
    {
        Storage::disk(config('cerne.document_vault.disk'))->delete($documento->storage_path);
        $documento->delete();
    }
}
