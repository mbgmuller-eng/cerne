<?php

namespace App\Support;

use App\Models\CreditCardInvoice;
use App\Models\DocumentUpload;
use App\Models\FixedBillPayment;
use App\Models\HealthAppointment;
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
            'trial_ending_soon' => [
                'heading' => 'Teste grátis terminando',
                'message' => 'Seu acesso gratuito vai até '.self::dia($d['trial_last_day'] ?? null).'. Escolha como pagar para continuar.',
                'url' => route('subscription.index'),
            ],
            'health_care_item_due' => [
                'heading' => 'Cuidado de saúde',
                'message' => self::juntar($titulo, isset($d['member_name']) ? 'para '.$d['member_name'] : null, ($d['days_left'] ?? 0) <= 0 ? 'é hoje' : 'é amanhã'),
                'url' => route('health.care.index'),
            ],
            'document_processed' => [
                'heading' => ($d['status'] ?? null) === 'completed' ? 'Importação concluída' : 'Falha na importação',
                'message' => $titulo,
                'url' => ($d['document_type'] ?? null) === 'insurance_policy' ? route('insurance.index') : route('documents.index'),
            ],
            default => [
                'heading' => $titulo !== '' ? $titulo : 'Notificação',
                'message' => null,
                'url' => $d['url'] ?? null,
            ],
        };
    }

    /**
     * Perfil financeiro dono do dado de que a notificação trata, quando ela
     * trata de dado de UM perfil (importação, conta fixa, fatura, agenda de
     * saúde). É o que o clique precisa abrir antes de ir pra tela: um
     * consultor que importou um documento dentro do perfil de um cliente
     * recebe o aviso sem nenhum cliente aberto, e a tela de destino o
     * devolveria à carteira.
     *
     * Aviso de carteira (aniversário, vencimento de apólice ou de
     * investimento) é do profissional como um todo, não de um perfil.
     */
    public static function profileId(DatabaseNotification $notificacao): ?string
    {
        $d = $notificacao->data;

        [$modelo, $id] = match ($d['kind'] ?? null) {
            'document_processed' => [DocumentUpload::class, $d['document_upload_id'] ?? null],
            'fixed_bill_due_soon' => [FixedBillPayment::class, $d['fixed_bill_payment_id'] ?? null],
            'credit_card_invoice_due_soon' => [CreditCardInvoice::class, $d['credit_card_invoice_id'] ?? null],
            'health_appointment_upcoming' => [HealthAppointment::class, $d['health_appointment_id'] ?? null],
            default => [null, null],
        };

        if ($modelo === null || $id === null) {
            return null;
        }

        return $modelo::withoutProfileScope()->whereKey($id)->value('profile_id');
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
