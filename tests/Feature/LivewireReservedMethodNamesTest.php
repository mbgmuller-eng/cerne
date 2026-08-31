<?php

namespace Tests\Feature;

use FilesystemIterator;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Varredura estrutural: nenhum componente Livewire pode ter uma ação pública
 * com um nome que o próprio $wire reserva como apelido (ver o objeto
 * `aliases` em vendor/livewire/livewire/dist/livewire.js — "upload", "call",
 * "get", "set", "watch", "dispatch" etc.).
 *
 * Foi assim que a importação de PDF quebrou: o método `upload()` da tela de
 * documentos colidia com o `$wire.upload` interno do Livewire (usado para
 * envio de arquivo). wire:submit="upload" nunca chegava no servidor — travava
 * no navegador, sem erro no console do PHP, sem log, sem registro no banco.
 * Um bug desses não aparece em teste de Livewire::test() porque o teste chama
 * o método PHP direto, sem passar pelo $wire do navegador.
 */
class LivewireReservedMethodNamesTest extends TestCase
{
    /** @var list<string> */
    private const RESERVED = [
        'on', 'el', 'id', 'js', 'get', 'set', 'refs', 'call', 'hook', 'watch',
        'dirty', 'effect', 'commit', 'errors', 'island', 'upload', 'entangle',
        'dispatch', 'intercept', 'interceptAction', 'interceptMessage',
        'interceptRequest', 'dispatchTo', 'dispatchSelf', 'dispatchEl',
        'dispatchRef', 'removeUpload', 'cancelUpload', 'uploadMultiple',
    ];

    public function test_nenhum_componente_livewire_define_acao_com_nome_reservado(): void
    {
        $conflitos = [];

        foreach ($this->livewireComponentClasses() as $classe) {
            $reflexao = new ReflectionClass($classe);

            foreach ($reflexao->getMethods(ReflectionMethod::IS_PUBLIC) as $metodo) {
                if ($metodo->getDeclaringClass()->getName() !== $classe) {
                    continue;
                }

                if (in_array($metodo->getName(), self::RESERVED, true)) {
                    $conflitos[] = "{$classe}::{$metodo->getName()}()";
                }
            }
        }

        self::assertSame(
            [],
            $conflitos,
            "Estes métodos usam um nome que o \$wire do Livewire reserva para si — wire:click/wire:submit para eles nunca chega no servidor:\n  "
                .implode("\n  ", $conflitos)
        );
    }

    /** @return list<class-string> */
    private function livewireComponentClasses(): array
    {
        $classes = [];

        $arquivos = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Livewire'), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($arquivos as $arquivo) {
            if ($arquivo->getExtension() !== 'php') {
                continue;
            }

            $relativo = str_replace(app_path('Livewire').DIRECTORY_SEPARATOR, '', $arquivo->getPathname());
            $classe = 'App\\Livewire\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relativo);

            if (class_exists($classe) && is_subclass_of($classe, Component::class)) {
                $classes[] = $classe;
            }
        }

        return $classes;
    }
}
