<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminMfaController;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Accruals\Index as AccrualsIndex;
use App\Livewire\Admin\Fees\Index as FeesIndex;
use App\Livewire\Admin\Deposits\Index as DepositsIndex;
use App\Livewire\Admin\Investors\Create as InvestorsCreate;
use App\Livewire\Admin\Investors\Index as InvestorsIndex;
use App\Livewire\Admin\Investors\Show as InvestorsShow;
use App\Livewire\Admin\Withdrawals\Index as WithdrawalsIndex;
use App\Livewire\Admin\Wallets\Index as WalletsIndex;
use App\Livewire\Admin\Audit\Index as AuditIndex;
use App\Livewire\Investor\Accruals as InvestorAccruals;
use App\Livewire\Investor\Dashboard as InvestorDashboard;
use App\Livewire\Investor\Finance as InvestorFinance;
use App\Livewire\Investor\Profile as InvestorProfile;
use App\Livewire\Investor\Wallets as InvestorWallets;
use App\Livewire\Investor\Withdrawals as InvestorWithdrawals;
use App\Livewire\Investor\Support\Index as InvestorSupportIndex;
use App\Livewire\Investor\Support\Show as InvestorSupportShow;
use App\Livewire\Admin\Inbox\Index as AdminInboxIndex;
use App\Livewire\Admin\InvestmentPrograms\Index as InvestmentProgramsIndex;
use App\Livewire\Admin\Notifications\Index as AdminNotificationsIndex;
use App\Livewire\Admin\Settings\Index as AdminSettingsIndex;
use App\Livewire\Admin\Administrators\Index as AdminAdministratorsIndex;
use App\Livewire\Investor\Programs as InvestorPrograms;
use App\Livewire\Investor\CreateDeposit as InvestorCreateDeposit;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'create']);

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/mfa/challenge', [AdminMfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [AdminMfaController::class, 'verifyChallenge'])->name('mfa.verify');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::middleware('role:investor')->group(function () {
        Route::get('/dashboard', InvestorDashboard::class)->name('dashboard');
        Route::get('/accruals', InvestorAccruals::class)->name('investor.accruals');
        Route::get('/finance', InvestorFinance::class)->name('investor.finance');
        Route::get('/withdrawals', InvestorWithdrawals::class)->name('investor.withdrawals');
        Route::get('/wallets', InvestorWallets::class)->name('investor.wallets');
        Route::get('/profile', InvestorProfile::class)->name('investor.profile');
        Route::get('/support', InvestorSupportIndex::class)->name('investor.support.index');
        Route::get('/support/{supportTicket}', InvestorSupportShow::class)->name('investor.support.show');
        Route::get('/programs', InvestorPrograms::class)->name('investor.programs');
        Route::get('/finance/create', InvestorCreateDeposit::class)->name('investor.finance.create');
    });

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/security', [AdminMfaController::class, 'setup'])->name('security.setup');
        Route::post('/security/enable', [AdminMfaController::class, 'enable'])->name('security.enable');
    });

    Route::prefix('admin')->name('admin.')->middleware(['role:admin', 'admin.mfa'])->group(function () {
        Route::get('/recent-auth', [AdminMfaController::class, 'recent'])->name('recent-auth');
        Route::post('/recent-auth', [AdminMfaController::class, 'verifyRecent'])->name('recent-auth.verify');
        Route::post('/security/recovery-codes', [AdminMfaController::class, 'regenerateRecovery'])->name('security.recovery');
        Route::delete('/security', [AdminMfaController::class, 'disable'])->name('security.disable');
        Route::get('/', Dashboard::class)->name('dashboard');
        Route::get('/investors', InvestorsIndex::class)->name('investors.index');
        Route::get('/investors/create', InvestorsCreate::class)->name('investors.create');
        Route::get('/investors/{investor}', InvestorsShow::class)->name('investors.show');
        Route::get('/deposits', DepositsIndex::class)->name('deposits.index');
        Route::get('/withdrawals', WithdrawalsIndex::class)->name('withdrawals.index');
        Route::get('/accruals', AccrualsIndex::class)->name('accruals.index');
        Route::get('/fees', FeesIndex::class)->name('fees.index');
        Route::get('/wallets', WalletsIndex::class)->name('wallets.index');
        Route::get('/audit', AuditIndex::class)->name('audit.index');
        Route::get('/inbox', AdminInboxIndex::class)->name('inbox.index');
        Route::get('/investment-programs', InvestmentProgramsIndex::class)->name('investment-programs.index');
        Route::get('/notifications', AdminNotificationsIndex::class)->name('notifications.index');
        Route::get('/settings', AdminSettingsIndex::class)->name('settings.index');
        Route::get('/administrators', AdminAdministratorsIndex::class)->name('administrators.index');
    });
});
