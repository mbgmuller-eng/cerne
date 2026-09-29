<?php

namespace App\Services;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\ProfileMember;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

        $categoria = DocumentCategory::from($dados['category']);

        return Document::create([
            'member_id' => $dados['member_id'] ?? null,
            'category' => $categoria,
            // Só apólice usa o vínculo; toda outra categoria ignora o campo,
            // mesmo se vier preenchido por engano.
            'insurance_policy_id' => $categoria === DocumentCategory::InsurancePolicy ? ($dados['insurance_policy_id'] ?? null) : null,
            'title' => trim($dados['title']),
            'original_filename' => $arquivo->getClientOriginalName(),
            'storage_path' => $caminho,
            'mime_type' => $arquivo->getMimeType() ?? $arquivo->getClientMimeType(),
            'size_bytes' => $arquivo->getSize(),
            'expires_on' => $dados['expires_on'] ?? null,
            // Só tem efeito pra category=other (ver DocumentVisibilityScope);
            // gravar mesmo assim não abre brecha nenhuma nas outras categorias.
            'visible_to_professional' => $categoria === DocumentCategory::Other && (bool) ($dados['visible_to_professional'] ?? false),
            'created_by_member_id' => $autor->id,
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
            'category' => $categoria,
            'insurance_policy_id' => $categoria === DocumentCategory::InsurancePolicy ? ($dados['insurance_policy_id'] ?? null) : null,
            'title' => trim($dados['title']),
            'expires_on' => $dados['expires_on'] ?? null,
            'visible_to_professional' => $categoria === DocumentCategory::Other && (bool) ($dados['visible_to_professional'] ?? false),
        ]);

        return $documento;
    }

    public function delete(Document $documento): void
    {
        Storage::disk(config('cerne.document_vault.disk'))->delete($documento->storage_path);
        $documento->delete();
    }
}
