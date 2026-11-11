<?php

namespace App\Http\Middleware;

use App\Services\EntitlementService;
use App\Support\ProfileContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dentro do perfil de uma pessoa acompanhada (cuidador, ou o dono do perfil de cuidado) só existem saúde e documentos. Quem acompanha as finanças de alguém (perfil comum) só abre as telas de leitura da família (family.*) e, com acesso à saúde, as de saúde.
 *
 * É a segunda trava, por rota. A primeira é a de dados (ProfileScope devolve zero linhas de finanças para o cuidador), mas uma tela de
 * finanças vazia sugeriria "essa pessoa não tem dívidas", em vez de dizer que ali não é lugar dele. A lista é de rotas PERMITIDAS: rota
 * nova nasce fechada para quem cuida.
 */
class RestrictCareContext
{
    /** Abertas mesmo com a assinatura do dono fora de vigor. */
    private const ALLOWED_ALWAYS = ['care.*', 'legal.*', 'logout', 'theme.update', 'push.subscribe', 'privacy.export.download', 'admin.impersonate.stop'];

    /** Nomes de rota (ou prefixo com *) que continuam abertos dentro do perfil de uma pessoa acompanhada. */
    private const ALLOWED = [
        'care.*',
        'health.card.index', 'health.qrcode.show', 'health.appointments.index', 'health.care.index', 'health.appointments.ics', 'health.care.ics',
        'documents.vault.index', 'documents.vault.file',
        'health.consent', 'health.consent.store', 'health.consent.revoke',
        'legal.*', 'logout', 'theme.update', 'push.subscribe', 'privacy.export.download', 'admin.impersonate.stop',
    ];

    /** As telas de leitura das finanças da família: só para quem tem acesso às finanças. */
    private const FAMILY = ['family.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $context = app(ProfileContext::class);
        $profile = $context->profile();

        if ($profile === null || ! ($context->isCaregiver() || $profile->isCare())) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED_ALWAYS)) {
            return $next($request);
        }

        // Quem paga é o dono: sem a assinatura dele em vigor, a saúde e os documentos desta pessoa ficam suspensos (os dados continuam guardados).
        // Só a lista de pessoas segue aberta, para ele regularizar ou apagar.
        if ($profile->isCare() && ! app(EntitlementService::class)->careProfileEntitled($profile)) {
            return redirect()->route('care.index')->with('status', 'O acesso a esta pessoa está suspenso: a assinatura de quem a cadastrou não está em vigor.');
        }

        // Finanças em leitura: só com o acesso liberado e só fora do perfil de cuidado (que não tem finanças).
        if ($context->isCaregiver() && ! $profile->isCare() && $context->hasFinanceAccess() && $request->routeIs(...self::FAMILY)) {
            return $next($request);
        }

        // Saúde e documentos: quem acompanha só as finanças não os abre.
        $saudeLiberada = ! $context->isCaregiver() || $context->hasHealthAccess();

        if ($saudeLiberada && $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        abort_unless($request->isMethod('GET'), 403);

        return redirect()->route($context->isCaregiver() && ! $context->hasHealthAccess() && $context->hasFinanceAccess() ? 'family.index' : 'care.index');
    }
}
