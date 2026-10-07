<?php

use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\AsaasWebhookController;
use App\Http\Controllers\Auth\AcceptInviteController;
use App\Http\Controllers\Auth\AcceptPartnerInviteController;
use App\Http\Controllers\Auth\AcceptProfessionalInviteController;
use App\Http\Controllers\Auth\CheckoutController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SelfRegistrationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\ConsultantLinkController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\GymExerciseCatalogImageController;
use App\Http\Controllers\GymExerciseImageController;
use App\Http\Controllers\HealthEmergencyController;
use App\Http\Controllers\HealthQrCodeController;
use App\Http\Controllers\ProfileSwitchController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\ThemePreferenceController;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\Admin\AdminBanks;
use App\Livewire\Admin\AdminExercises;
use App\Livewire\Admin\AdminInsurers;
use App\Livewire\Admin\AdminUsers;
use App\Livewire\Accounts\InvoiceShow;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Livewire\CategorizationRules\CategorizationRulesIndex;
use App\Livewire\Consultant\ImportantDates;
use App\Livewire\Consultant\LeadsIndex;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Livewire\Consultant\PortfolioInvestments;
use App\Livewire\Consultant\PortfolioOverview;
use App\Livewire\Dashboard;
use App\Livewire\Documents\DocumentsIndex;
use App\Livewire\Documents\DocumentVaultIndex;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Livewire\Goals\GoalsIndex;
use App\Livewire\Health\Gym\GymExerciseHistory;
use App\Livewire\Health\Gym\GymHome;
use App\Livewire\Health\Gym\GymPlanEditor;
use App\Livewire\Health\Gym\GymProgress;
use App\Livewire\Health\Gym\GymSessionHistory;
use App\Livewire\Health\Gym\GymSessionRun;
use App\Livewire\Health\Gym\GymWorkoutShow;
use App\Livewire\Health\HealthAppointmentIndex;
use App\Livewire\Health\HealthCardIndex;
use App\Livewire\Insurance\InsuranceIndex;
use App\Livewire\Investments\InvestmentsIndex;
use App\Livewire\Profile\MyAccount;
use App\Livewire\Subscription\SubscriptionIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

// Vitrine pública: quem já está logado não precisa ver, cai direto no painel.
Route::get('/', fn () => Auth::check() ? redirect()->route('dashboard') : view('landing'))->name('home');

Route::view('/termos', 'legal.terms')->name('legal.terms');

// Página pública pra consultor, corretor e (em breve) profissional de saúde.
// A landing em / é só pro usuário final.
Route::view('/profissionais', 'professionals')->name('professionals');

/*
| Visitantes
|
| Cadastro próprio (sem convite) também entra aqui — cliente ou
| profissional, ver SelfRegistrationController.
|
| Estas telas usam POST HTML puro, sem Livewire — ver LoginController.
*/
Route::middleware('guest')->group(function (): void {
    Route::get('/entrar', [LoginController::class, 'show'])->name('login');
    Route::post('/entrar', [LoginController::class, 'store'])->name('login.store');

    // Compra: resumo do pedido + criação de conta. A rota fixa de profissional
    // vem antes de /comprar/{pacote} pra "profissional" não ser lido como pacote.
    Route::get('/comprar/profissional', [CheckoutController::class, 'showProfessional'])->name('checkout.professional');
    Route::get('/comprar/{pacote}', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/comprar', [CheckoutController::class, 'store'])->name('checkout.store');

    Route::get('/cadastro', [SelfRegistrationController::class, 'show'])->name('register');
    Route::post('/cadastro', [SelfRegistrationController::class, 'store'])->name('register.store');

    Route::get('/convite/{token}', [AcceptInviteController::class, 'show'])->name('invite.accept');
    Route::post('/convite/{token}', [AcceptInviteController::class, 'store'])->name('invite.store');

    Route::get('/convite-conjuge/{token}', [AcceptPartnerInviteController::class, 'show'])->name('partner-invite.accept');
    Route::post('/convite-conjuge/{token}', [AcceptPartnerInviteController::class, 'store'])->name('partner-invite.store');

    Route::get('/convite-profissional/{token}', [AcceptProfessionalInviteController::class, 'show'])->name('professional-invite.accept');
    Route::post('/convite-profissional/{token}', [AcceptProfessionalInviteController::class, 'store'])->name('professional-invite.store');
});

// QR Code de emergência: pública de propósito (quem escaneia é um
// socorrista ou familiar sem conta) — token opaco no lugar de login, ver
// HealthCardService::emergencyPayload().
Route::get('/saude/emergencia/{token}', [HealthEmergencyController::class, 'show'])->name('health.emergency.show');

// Webhook da Asaas: pública de propósito (é a Asaas chamando, não uma
// pessoa logada) — autenticação é o header asaas-access-token, conferido
// dentro do controller, não aqui.
Route::post('/webhooks/asaas', [AsaasWebhookController::class, 'handle'])->name('webhooks.asaas');

/*
| Confirmação de e-mail — fora do grupo "verified" abaixo de propósito,
| senão vira loop: o middleware redireciona pra cá justamente quando a
| conta ainda não está verificada.
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/verificar-email', fn () => view('auth.verify-email'))->name('verification.notice');

    Route::get('/verificar-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/verificar-email/reenviar', function (Request $request): RedirectResponse {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'Link reenviado — confira seu e-mail.');
    })->middleware('throttle:6,1')->name('verification.send');

    // Sair precisa estar alcançável mesmo sem e-mail verificado — senão
    // quem se cadastrou e ainda não confirmou fica preso sem conseguir
    // nem deslogar (o "verified" do grupo abaixo bloquearia isto também).
    Route::post('/sair', [LoginController::class, 'destroy'])->name('logout');
});

/*
| Autenticados
|
| "verified" barra quem se cadastrou sozinho e ainda não confirmou o
| e-mail — conta vinda de convite já nasce com email_verified_at
| carimbado (ClientOnboardingService/ProfessionalOnboardingService), não
| muda de comportamento pra ela.
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    // Fora do bloqueio por assinatura: o necessário para pagar e para sair
    // (ver RequiresActiveSubscription).
    Route::get('/minha-conta', MyAccount::class)->name('my-account');
    Route::get('/assinatura', SubscriptionIndex::class)->name('subscription.index');
    Route::post('/preferencias/tema', [ThemePreferenceController::class, 'store'])->name('theme.update');
    Route::post('/preferencias/push', [PushSubscriptionController::class, 'store'])->name('push.subscribe');

    // "Entrar como" começa dentro do componente Livewire (AdminUsers::
    // entrarComo, Auth::login direto); só o "voltar" precisa de rota —
    // o banner fica em todo canto do app enquanto a personificação dura.
    Route::post('/admin/sair-da-personificacao', [ImpersonationController::class, 'stop'])->name('admin.impersonate.stop');

    // Vínculo consultor↔cliente quando o e-mail convidado já tem conta —
    // ver ConsultantLinkService. Só "show" carrega assinatura (vem do
    // e-mail/link mostrado na tela); accept/decline são POST comuns
    // dentro da própria página, protegidos pela policy.
    Route::get('/vinculo/{consultantClient}', [ConsultantLinkController::class, 'show'])->name('link.show')->middleware('signed');
    Route::post('/vinculo/{consultantClient}/autorizar', [ConsultantLinkController::class, 'accept'])->name('link.accept');
    Route::post('/vinculo/{consultantClient}/recusar', [ConsultantLinkController::class, 'decline'])->name('link.decline');

    Route::middleware('assinatura')->group(function (): void {
        Route::get('/painel', Dashboard::class)->name('dashboard');

        Route::get('/fluxo-de-caixa', CashFlowIndex::class)->name('cashflow.index');
        Route::get('/contas-fixas', FixedBillsIndex::class)->name('fixedbills.index');
        Route::get('/investimentos', InvestmentsIndex::class)->name('investments.index');
        Route::get('/seguros', InsuranceIndex::class)->name('insurance.index');
        Route::get('/objetivos', GoalsIndex::class)->name('goals.index');

        // Documentos: visível ao consultor/corretor também (ao contrário de
        // Saúde) — só que filtrado por categoria (DocumentVisibilityScope).
        Route::get('/documentos', DocumentVaultIndex::class)->name('documents.vault.index');
        Route::get('/documentos/{document}/arquivo', [DocumentFileController::class, 'show'])->name('documents.vault.file');

        // Saúde pessoal: só o dono abre (RequiresPersonalHealth) — consultor
        // e corretor levam 403 mesmo com o cliente aberto.
        Route::get('/saude/academia', GymHome::class)->name('health.gym.index');
        Route::get('/saude/academia/plano', GymPlanEditor::class)->name('health.gym.plan');
        // Plural de propósito: "treino/{session}" (abaixo) é a SESSÃO em andamento;
        // "treinos/{workout}" é o treino do PLANO, só pra consultar antes de começar.
        Route::get('/saude/academia/treinos/{workout}', GymWorkoutShow::class)->name('health.gym.workout');
        Route::get('/saude/academia/treino/{session}', GymSessionRun::class)->name('health.gym.session');
        Route::get('/saude/academia/historico', GymSessionHistory::class)->name('health.gym.history');
        Route::get('/saude/academia/progresso', GymProgress::class)->name('health.gym.progress');
        Route::get('/saude/academia/exercicio/{exercise}', GymExerciseHistory::class)->name('health.gym.exercise');
        Route::get('/saude/academia/exercicios/{exercise}/imagem', [GymExerciseImageController::class, 'show'])->name('health.gym.exercise-image');
        // Catálogo compartilhado: não é dado pessoal, não passa por RequiresPersonalHealth — qualquer autenticado pode ver.
        Route::get('/saude/academia/catalogo/{exercise}/imagem', [GymExerciseCatalogImageController::class, 'show'])->name('health.gym.catalog-image');

        // Ficha de saúde: visível aos DOIS do casal (CoupleHealthScope) —
        // diferente da Academia, mas com o mesmo bloqueio a consultor/corretor.
        Route::get('/saude/ficha', HealthCardIndex::class)->name('health.card.index');
        Route::get('/saude/ficha/{memberId}/qrcode', [HealthQrCodeController::class, 'show'])->name('health.qrcode.show');
        Route::get('/saude/agenda', HealthAppointmentIndex::class)->name('health.appointments.index');
        Route::get('/importar', DocumentsIndex::class)->name('documents.index');
        Route::get('/regras-de-categorizacao', CategorizationRulesIndex::class)->name('categorization-rules.index');
        Route::get('/contas', AccountsIndex::class)->name('accounts.index');
        Route::get('/faturas/{invoice}', InvoiceShow::class)->name('invoices.show');

        Route::get('/carteira', PortfolioOverview::class)->name('consultant.portfolio');
        Route::get('/carteira/seguros', PortfolioInsurance::class)->name('consultant.portfolio.insurance');
        Route::get('/carteira/investimentos', PortfolioInvestments::class)->name('consultant.portfolio.investments');
        Route::get('/carteira/datas-importantes', ImportantDates::class)->name('consultant.portfolio.important-dates');
        Route::get('/leads', LeadsIndex::class)->name('consultant.leads');
        Route::post('/clientes/{profile}/abrir', [ProfileSwitchController::class, 'store'])->name('profile.switch');

        // Gestão de toda a plataforma — gate é isPlatformAdmin() dentro do
        // mount() do componente, mesmo padrão de PortfolioOverview::mount().
        Route::get('/admin', AdminUsers::class)->name('admin.users');
        Route::get('/admin/bancos', AdminBanks::class)->name('admin.banks');
        Route::get('/admin/seguradoras', AdminInsurers::class)->name('admin.insurers');
        Route::get('/admin/exercicios', AdminExercises::class)->name('admin.exercises');
    });
});
