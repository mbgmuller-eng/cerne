<?php

namespace App\Support\Engagement;

use App\Models\Document;
use App\Models\FinancialProfile;
use Illuminate\Support\Collection;

/**
 * Documentos da área de Documentos que podem ser anexados ao acompanhamento: só os que o consultor também pode ver.
 *
 * A regra de quem vê o quê continua sendo a do próprio documento (DocumentVisibilityScope): apólice (herda da apólice) e a
 * categoria "outros" marcada como visível ao profissional. Documento pessoal ou de saúde (CNH, passaporte, exame) nunca entra,
 * nem se o cliente quiser, porque a regra daquela área é dura. Assim anexar nunca abre o que estava fechado.
 */
final class EngagementDocuments
{
    /** @return Collection<int, Document> */
    public static function shareable(FinancialProfile $perfil): Collection
    {
        return AsConsultant::run($perfil, fn () => Document::query()->orderBy('title')->get());
    }
}
