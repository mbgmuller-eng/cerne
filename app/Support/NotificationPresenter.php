<?php

namespace App\Support;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Transforma uma notificação gravada (coluna `data`) no que o sino mostra:
 * um título que diz do que se trata, uma linha de contexto e a tela pra onde
 * o clique leva.
 *
 * Monta tudo na hora de exibir, a partir de `kind` e dos campos que cada
 * notificação já grava, em vez de gravar texto e URL prontos: assim as
 * notificações antigas (que só têm `title` e os campos crus) ganham o mesmo
 * tratamento das novas, sem migrar nada, e uma rota renomeada não deixa
 * link morto no histórico.
 */
final class NotificationPresenter
{
    /** @return array{heading: string, message: ?string, url: ?string} */
    public static function present(DatabaseNotification $notificacao): array
    {
        $d = $notificacao->data;
        $titulo = (string) ($d['title'] ?? '');

        return match ($d['kind'] ?? null) {
            'client_birthday_upcoming' => [
                'heading' => 'Aniversário chegando',
                'message' => self::juntar($titulo, 'completa '.($d['turning_age'] ?? '?').' anos em '.self::dia($d['occurrence_date'] ?? null)),
                'url' => route('consultant.portfolio.important-dates'),
            ],
            'insurance_policy_anniversary_upcoming' => [
                'heading' => 'Aniversário de apólice',
                'message' => 'Apólice '.self::comPessoa($titulo, $d).' completa '.($d['years_completing'] ?? '?').' ano(s) em '.self::dia($d['occurrence_date'] ?? null),
                'url' => route('consultant.portfolio.important-dates'),
            ],
            'insurance_policy_expiring_upcoming' => [
                'heading' => 'Apólice vencendo',
                'message' => 'Apólice '.self::comPessoa($titulo, $d).' vence em '.self::dia($d['expiry_date'] ?? null),
                'url' => route('consultant.portfolio.important-dates'),
            ],
            'investment_maturity_upcoming' => [
                'heading' => 'Investimento vencendo',
                'message' => $titulo.' vence em '.self::dia($d['maturity_date'] ?? null),
                'url' => route('consultant.portfolio.important-dates'),
            ],
            'fixed_bill_due_soon' => [
                'heading' => 'Conta a vencer',
                'message' => $titulo.' vence em '.self::dia($d['due_date'] ?? null).self::valor($d['amount'] ?? null),
                'url' => route('fixedbills.index'),
            ],
            'credit_card_invoice_due_soon' => [
                'heading' => 'Fatura a vencer',
                'message' => $titulo.' vence em '.self::dia($d['due_date'] ?? null).self::valor($d['amount'] ?? null),
                'url' => isset($d['credit_card_invoice_id']) ? route('invoices.show', $d['credit_card_invoice_id']) : route('accounts.index'),
            ],
            'health_appointment_upcoming' => [
                'heading' => 'Agenda de saúde',
                'message' => self::juntar($titulo, isset($d['member_name']) ? 'para '.$d['member_name'] : null, isset($d['scheduled_at']) ? 'em '.Carbon::parse($d['scheduled_at'])->format('d/m \à\s H:i') : null),
                'url' => route('health.appointments.index'),
            ],
            'document_processed' => [
                'heading' => ($d['status'] ?? null) === 'completed' ? 'Importação concluída' : 'Falha na importação',
                'message' => $titulo,
                'url' => route('documents.index'),
            ],
            default => [
                'heading' => $titulo !== '' ? $titulo : 'Notificação',
                'message' => null,
                'url' => $d['url'] ?? null,
            ],
        };
    }

    private static function dia(?string $data): string
    {
        return $data === null ? '?' : Carbon::parse($data)->format('d/m');
    }

    private static function valor(?string $valor): string
    {
        return $valor === null ? '' : ' ('.Money::format($valor).')';
    }

    /** "Allianz (Carolina)" quando a notificação sabe de quem é a apólice. */
    private static function comPessoa(string $titulo, array $dados): string
    {
        $pessoa = $dados['person_label'] ?? null;

        return $pessoa ? "{$titulo} ({$pessoa})" : $titulo;
    }

    private static function juntar(?string ...$partes): string
    {
        return implode(' ', array_filter($partes, fn (?string $p) => $p !== null && $p !== ''));
    }
}
