<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sugestão de venda cruzada que o profissional dispensou. Só existe para sustentar o índice único (profissional +
 * chave da sugestão) e para a sugestão não voltar (ver LeadAutomationService).
 */
#[Fillable(['consultant_id', 'suggestion_key'])]
class LeadSuggestionDismissal extends Model
{
    use HasUuids;
}
