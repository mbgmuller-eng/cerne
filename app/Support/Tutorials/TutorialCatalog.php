<?php

namespace App\Support\Tutorials;

/**
 * Os tutoriais das telas, lidos de config/tutorials.php e indexados pelo NOME DA ROTA (é também a chave do que a pessoa já viu).
 * Tela sem entrada no catálogo não ganha botão "?" nem cartão de primeira visita: nunca existe um botão sem conteúdo por trás.
 *
 * Cada entrada: title, summary (uma frase, aparece no cartão), steps (de 3 a 6 passos) e, opcionalmente, tip.
 */
final class TutorialCatalog
{
    /** @return array{title: string, summary: string, steps: list<string>, tip?: string}|null */
    public static function for(?string $routeName): ?array
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        // Os nomes de rota têm ponto (cashflow.index): ler o array inteiro evita que o config() os trate como níveis.
        return self::all()[$routeName] ?? null;
    }

    public static function has(?string $routeName): bool
    {
        return self::for($routeName) !== null;
    }

    /** @return array<string, array{title: string, summary: string, steps: list<string>, tip?: string}> */
    public static function all(): array
    {
        return (array) config('tutorials.pages', []);
    }
}
