<?php

namespace App\Services\Privacy;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\BelongsToProfileOrShared;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\FinancialProfile;
use App\Models\ImpersonationLog;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * "Baixar meus dados" (LGPD, art. 18, II e V): um zip com o que o Cerne guarda da pessoa, em JSON legível por máquina, mais os documentos do cofre.
 *
 * A exportação roda SOB o ProfileContext da própria pessoa, perfil a perfil, com todos os escopos globais ligados. Isso é de propósito: o
 * escopo de privacidade do casal e o de saúde pessoal decidem o que ela pode ver, e a exportação não pode ser um caminho que mostre o
 * lançamento privado do cônjuge. O que ela não vê na tela, não sai no zip.
 */
class UserDataExporter
{
    /** Acima disso, os arquivos do cofre ficam de fora do zip (a hospedagem tem disco e CPU contados) e a lista do que faltou vai no LEIA-ME. */
    private const MAX_FILES_BYTES = 200 * 1024 * 1024;

    public const TTL_DAYS = 7;

    public function __construct(private readonly ProfileContext $context) {}

    /** Monta o zip, grava no disco privado e devolve o pedido já pronto. */
    public function export(User $user, ?DataExport $pedido = null): DataExport
    {
        $pedido ??= DataExport::query()->create(['user_id' => $user->id, 'status' => DataExport::PENDING]);

        $tmp = tempnam(sys_get_temp_dir(), 'cerne-export-');
        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo da exportação.');
        }

        $temporarios = [];
        $faltou = [];

        try {
            $this->addJson($zip, 'conta.json', $this->account($user), $temporarios);

            $bytesArquivos = 0;

            foreach ($this->memberships($user) as $membro) {
                $perfil = $membro->profile;
                $this->context->set($perfil, $membro);

                try {
                    $pasta = 'perfis/'.$this->slug($perfil->profile_name ?? $perfil->id).'-'.substr($perfil->id, 0, 8);
                    $this->addJson($zip, $pasta.'/perfil.json', ['perfil' => $perfil->attributesToArray(), 'membros' => ProfileMember::query()->where('profile_id', $perfil->id)->get()->map->attributesToArray()->all()], $temporarios);

                    foreach ($this->domainModels() as $classe) {
                        $this->addModel($zip, $pasta, $classe, $perfil, $temporarios);
                    }

                    $this->addVaultFiles($zip, $pasta, $perfil, $bytesArquivos, $faltou);
                } finally {
                    $this->context->clear();
                }
            }

            $zip->addFromString('LEIA-ME.txt', $this->readme($user, $faltou));
            $zip->close();
        } catch (Throwable $e) {
            $zip->close();
            @unlink($tmp);
            $this->cleanup($temporarios);
            $pedido->update(['status' => DataExport::FAILED]);

            throw $e;
        }

        $this->cleanup($temporarios);

        $caminho = 'exports/'.$user->id.'/'.$pedido->id.'.zip';
        Storage::disk('local')->put($caminho, fopen($tmp, 'rb'));
        $tamanho = filesize($tmp);
        @unlink($tmp);

        $pedido->update([
            'status' => DataExport::READY, 'path' => $caminho, 'size_bytes' => $tamanho,
            'ready_at' => now(), 'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return $pedido->refresh();
    }

    /** @return array<string, mixed> */
    private function account(User $user): array
    {
        return [
            'usuario' => $user->attributesToArray(),
            'assinaturas' => Subscription::query()->where('user_id', $user->id)->get()->map->attributesToArray()->all(),
            'dados_de_cobranca' => $user->billingDetail?->attributesToArray(),
            'notificacoes' => DB::table('notifications')->where('notifiable_id', $user->id)->orderByDesc('created_at')->limit(1000)->get(['type', 'data', 'read_at', 'created_at'])->all(),
            // Transparência: quando a equipe do Cerne entrou na conta para dar suporte, e por quê. Sem o e-mail de quem entrou.
            'acessos_de_suporte' => ImpersonationLog::query()->where('target_user_id', $user->id)->orderBy('started_at')->get(['started_at', 'ended_at', 'reason'])->map->attributesToArray()->all(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, ProfileMember> */
    private function memberships(User $user)
    {
        return ProfileMember::query()->where('user_id', $user->id)->with('profile')->get()->filter(fn ($m) => $m->profile !== null)->values();
    }

    /** @return list<class-string<Model>> */
    private function domainModels(): array
    {
        $classes = [];

        foreach (glob(app_path('Models/*.php')) as $arquivo) {
            $classe = 'App\\Models\\'.pathinfo($arquivo, PATHINFO_FILENAME);

            if (! class_exists($classe) || ! is_subclass_of($classe, Model::class)) {
                continue;
            }

            $traits = class_uses_recursive($classe);

            if (in_array(BelongsToProfile::class, $traits, true) || in_array(BelongsToProfileOrShared::class, $traits, true)) {
                $classes[] = $classe;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Um model por arquivo, gravado em partes para não carregar a tabela inteira na memória. Linha da taxonomia compartilhada
     * (profile_id nulo) não é dado da pessoa e fica de fora.
     *
     * @param  class-string<Model>  $classe
     * @param  list<string>  $temporarios
     */
    private function addModel(ZipArchive $zip, string $pasta, string $classe, FinancialProfile $perfil, array &$temporarios): void
    {
        /** @var Model $modelo */
        $modelo = new $classe;

        $consulta = $classe::query()->where($modelo->qualifyColumn('profile_id'), $perfil->id)->orderBy($modelo->getQualifiedKeyName());

        if (! $consulta->clone()->exists()) {
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cerne-model-');
        $temporarios[] = $tmp;
        $f = fopen($tmp, 'wb');
        fwrite($f, '[');
        $primeiro = true;

        $consulta->chunk(500, function ($linhas) use ($f, &$primeiro): void {
            foreach ($linhas as $linha) {
                fwrite($f, ($primeiro ? '' : ',')."\n".json_encode($linha->attributesToArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
                $primeiro = false;
            }
        });

        fwrite($f, "\n]");
        fclose($f);

        $zip->addFile($tmp, $pasta.'/'.$this->slug(class_basename($classe)).'.json');
    }

    /**
     * Os documentos do cofre que a pessoa pode ver (o escopo de visibilidade já está ligado).
     *
     * @param  list<string>  $faltou
     */
    private function addVaultFiles(ZipArchive $zip, string $pasta, FinancialProfile $perfil, int &$bytes, array &$faltou): void
    {
        $disco = Storage::disk((string) config('cerne.document_vault.disk'));

        Document::query()->where('profile_id', $perfil->id)->orderBy('id')->chunk(100, function ($docs) use ($zip, $pasta, $disco, &$bytes, &$faltou): void {
            foreach ($docs as $doc) {
                $nome = $this->slug((string) ($doc->title ?? 'documento')).'-'.substr($doc->id, 0, 8).($doc->storage_path ? '.'.pathinfo($doc->storage_path, PATHINFO_EXTENSION) : '');

                if ($doc->storage_path === null || ! $disco->exists($doc->storage_path)) {
                    continue;
                }

                $tamanho = (int) $disco->size($doc->storage_path);

                if ($bytes + $tamanho > self::MAX_FILES_BYTES) {
                    $faltou[] = $nome;

                    continue;
                }

                $conteudo = $disco->get($doc->storage_path);

                if ($conteudo !== null) {
                    $zip->addFromString($pasta.'/documentos/'.$nome, $conteudo);
                    $bytes += $tamanho;
                }
            }
        });
    }

    /** @param  list<string>  $temporarios */
    private function addJson(ZipArchive $zip, string $nome, mixed $dados, array &$temporarios): void
    {
        $zip->addFromString($nome, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** @param  list<string>  $temporarios */
    private function cleanup(array $temporarios): void
    {
        foreach ($temporarios as $arquivo) {
            @unlink($arquivo);
        }
    }

    /** @param  list<string>  $faltou */
    private function readme(User $user, array $faltou): string
    {
        $linhas = [
            'Cerne: cópia dos seus dados',
            'Gerada em '.now()->format('d/m/Y H:i').' para '.$user->email.'.',
            '',
            'conta.json: seus dados de cadastro, assinatura, avisos recebidos e as vezes em que a equipe do Cerne entrou na sua conta para dar suporte.',
            'perfis/: uma pasta por perfil financeiro de que você faz parte, com um arquivo JSON por tipo de informação.',
            'Cada pasta traz só o que você pode ver no aplicativo. O que o seu cônjuge marcou como privado não aparece aqui.',
            'documentos/: os arquivos do seu cofre de documentos.',
            '',
            'Os arquivos JSON podem ser abertos em qualquer editor de texto ou importados em planilhas e outros sistemas.',
        ];

        if ($faltou !== []) {
            $linhas[] = '';
            $linhas[] = 'Estes documentos não couberam no limite de tamanho do arquivo e ficaram de fora. Baixe-os pelo cofre de documentos:';
            foreach ($faltou as $nome) {
                $linhas[] = '- '.$nome;
            }
        }

        return implode("\n", $linhas)."\n";
    }

    private function slug(string $texto): string
    {
        $texto = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto) ?? '', '-'));

        return $texto === '' ? 'item' : mb_substr($texto, 0, 60);
    }
}
