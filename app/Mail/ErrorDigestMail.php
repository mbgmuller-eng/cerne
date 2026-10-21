<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Relatório diário de erros da plataforma (ver ErrorDigest). Sem dado de cliente: a mensagem já vem limpa do registro. */
class ErrorDigestMail extends Mailable
{
    /** @param  array<string, mixed>  $dados */
    public function __construct(public array $dados) {}

    public function envelope(): Envelope
    {
        $dia = $this->dados['day']->format('d/m');
        $n = $this->dados['occurrences'];

        return new Envelope(
            subject: $n === 0
                ? "Cerne: nenhum erro em {$dia}"
                : "Cerne: {$n} ".($n === 1 ? 'erro' : 'erros')." em {$dia} ({$this->dados['types']} ".($this->dados['types'] === 1 ? 'tipo' : 'tipos').')',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.error-digest', with: ['d' => $this->dados, 'url' => route('admin.errors')]);
    }
}
