<?php

use App\Http\Controllers\Auth\AcceptInviteController;
use App\Http\Controllers\Auth\AcceptPartnerInviteController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ConsultantLinkController;
use App\Http\Controllers\ProfileSwitchController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\ThemePreferenceController;
use App\Livewire\Accounts\AccountsIndex;
use App\Livewire\Accounts\InvoiceShow;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Livewire\Consultant\PortfolioInvestments;
use App\Livewire\Consultant\PortfolioOverview;
use App\Livewire\Dashboard;
use App\Livewire\Documents\DocumentsIndex;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Livewire\Goals\GoalsIndex;
use App\Livewire\Insurance\InsuranceIndex;
use App\Livewire\Investments\InvestmentsIndex;
use App\Livewire\Profile\MyAccount;
use Illuminate\Support\Facades\Route;

Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

Route::redirect('/', '/painel');

/*
| Visitantes
|
| Não há cadastro público: o acesso ao Cerne nasce do convite de um
| consultor (seção 2 da especificação).
|
| Estas telas usam POST HTML puro, sem Livewire — ver LoginController.
*/
Route::middleware('guest')->group(function (): void {
    Route::get('/entrar', [LoginController::class, 'show'])->name('login');
    Route::post('/entrar', [LoginController::class, 'store'])->name('login.store');

    Route::get('/convite/{token}', [AcceptInviteController::class, 'show'])->name('invite.accept');
    Route::post('/convite/{token}', [AcceptInviteController::class, 'store'])->name('invite.store');

    Route::get('/convite-conjuge/{token}', [AcceptPartnerInviteController::class, 'show'])->name('partner-invite.accept');
    Route::post('/convite-conjuge/{token}', [AcceptPartnerInviteController::class, 'store'])->name('partner-invite.store');
});

/*
| Autenticados
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/painel', Dashboard::class)->name('dashboard');
    Route::get('/minha-conta', MyAccount::class)->name('my-account');
    Route::post('/preferencias/tema', [ThemePreferenceController::class, 'store'])->name('theme.update');
    Route::post('/preferencias/push', [PushSubscriptionController::class, 'store'])->name('push.subscribe');

    Route::get('/fluxo-de-caixa', CashFlowIndex::class)->name('cashflow.index');
    Route::get('/contas-fixas', FixedBillsIndex::class)->name('fixedbills.index');
    Route::get('/investimentos', InvestmentsIndex::class)->name('investments.index');
    Route::get('/seguros', InsuranceIndex::class)->name('insurance.index');
    Route::get('/objetivos', GoalsIndex::class)->name('goals.index');
    Route::get('/importar', DocumentsIndex::class)->name('documents.index');
    Route::get('/contas', AccountsIndex::class)->name('accounts.index');
    Route::get('/faturas/{invoice}', InvoiceShow::class)->name('invoices.show');

    Route::get('/carteira', PortfolioOverview::class)->name('consultant.portfolio');
    Route::get('/carteira/seguros', PortfolioInsurance::class)->name('consultant.portfolio.insurance');
    Route::get('/carteira/investimentos', PortfolioInvestments::class)->name('consultant.portfolio.investments');
    Route::post('/clientes/{profile}/abrir', [ProfileSwitchController::class, 'store'])->name('profile.switch');

    // Vínculo consultor↔cliente quando o e-mail convidado já tem conta —
    // ver ConsultantLinkService. Só "show" carrega assinatura (vem do
    // e-mail/link mostrado na tela); accept/decline são POST comuns
    // dentro da própria página, protegidos pela policy.
    Route::get('/vinculo/{consultantClient}', [ConsultantLinkController::class, 'show'])->name('link.show')->middleware('signed');
    Route::post('/vinculo/{consultantClient}/autorizar', [ConsultantLinkController::class, 'accept'])->name('link.accept');
    Route::post('/vinculo/{consultantClient}/recusar', [ConsultantLinkController::class, 'decline'])->name('link.decline');

    Route::post('/sair', [LoginController::class, 'destroy'])->name('logout');
});
