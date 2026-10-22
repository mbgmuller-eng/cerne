<?php

namespace App\Http\Middleware;

use App\Support\ProfileContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dentro do perfil de uma pessoa acompanhada (cuidador, ou o dono do perfil de cuidado) só existem saúde e documentos.
 *
 * É a segunda trava, por rota. A primeira é a de dados (ProfileScope devolve zero linhas de finanças para o cuidador), mas uma tela de
 * finanças vazia sugeriria "essa pessoa não tem dívidas", em vez de dizer que ali não é lugar dele. A lista é de rotas PERMITIDAS: rota
 * nova nasce fechada para quem cuida.
 */
class RestrictCareContext
{
    /** Nomes de rota (ou prefixo com *) que continuam abertos dentro do perfil de uma pessoa acompanhada. */
    private const ALLOWED = [
        'care.*',
        'health.card.index', 'health.qrcode.show', 'health.appointments.index', 'health.care.index', 'health.appointments.ics', 'health.care.ics',
        'documents.vault.index', 'documents.vault.file',
        'health.consent', 'health.consent.store', 'health.consent.revoke',
        'legal.*', 'logout', 'theme.update', 'push.subscribe', 'privacy.export.download',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $context = app(ProfileContext::class);
        $profile = $context->profile();

        if ($profile === null || ! ($context->isCaregiver() || $profile->isCare())) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        abort_unless($request->isMethod('GET'), 403);

        return redirect()->route('care.index');
    }
}
