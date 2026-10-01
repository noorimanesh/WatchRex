<?php

use App\Http\Controllers\Admin\ProbeController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AgentInstallController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DependencyController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicStatusController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\SubscriberController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

// ── Public ────────────────────────────────────────────────────────────────
Route::get('/status/{slug}', [PublicStatusController::class, 'show'])->name('status.show');
Route::get('/status/{slug}/json', [PublicStatusController::class, 'json'])->name('status.json');
Route::get('/status/{slug}/rss', [PublicStatusController::class, 'rss'])->name('status.rss');
Route::get('/badge/{uuid}/{kind}.svg', [PublicStatusController::class, 'badge'])
    ->whereIn('kind', ['status', 'uptime', 'response'])->middleware('throttle:120,1')->name('badge');
Route::get('/agent/install.sh', [AgentInstallController::class, 'installer'])->name('agent.installer');
Route::get('/agent/watchrex-agent.sh', [AgentInstallController::class, 'agent'])->name('agent.script');
Route::get('/agent/install.ps1', [AgentInstallController::class, 'windowsInstaller'])->name('agent.installer.windows');
Route::get('/agent/watchrex-agent.ps1', [AgentInstallController::class, 'windowsAgent'])->name('agent.script.windows');

Route::post('/status/{slug}/subscribe', [SubscriberController::class, 'subscribe'])->middleware('throttle:5,1')->name('status.subscribe');
Route::get('/status/{slug}/confirm/{token}', [SubscriberController::class, 'confirm'])->name('status.confirm');
Route::get('/status/{slug}/unsubscribe/{token}', [SubscriberController::class, 'unsubscribe'])->name('status.unsubscribe');

Route::get('/billing/callback/{number}', [BillingController::class, 'callback'])->middleware('throttle:30,1')->name('billing.callback');

// ── Authentication ────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.challenge');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:20,1');
    Route::get('/auth/sso', [SsoController::class, 'redirect'])->middleware('throttle:20,1')->name('sso.redirect');
    Route::get('/auth/sso/callback', [SsoController::class, 'callback'])->middleware('throttle:20,1')->name('sso.callback');
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ── Application ───────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/live', [DashboardController::class, 'live'])->name('dashboard.live');

    Route::resource('monitors', MonitorController::class);
    Route::post('/monitors/{monitor}/toggle', [MonitorController::class, 'toggle'])->name('monitors.toggle');
    Route::post('/monitors/{monitor}/check', [MonitorController::class, 'checkNow'])->middleware('throttle:10,1')->name('monitors.check');
    Route::post('/monitors/{monitor}/push-token', [MonitorController::class, 'regenerateToken'])->name('monitors.push-token');

    Route::resource('groups', GroupController::class);
    Route::post('/groups/{group}/members', [GroupController::class, 'members'])->name('groups.members');

    Route::get('/dependencies', [DependencyController::class, 'index'])->name('dependencies.index');
    Route::get('/dependencies/live', [DependencyController::class, 'live'])->name('dependencies.live');

    Route::resource('servers', ServerController::class);
    Route::post('/servers/{server}/token', [ServerController::class, 'rotateToken'])->name('servers.token');
    Route::post('/servers/{server}/sites', [ServerController::class, 'sitesAction'])->middleware('throttle:20,1')->name('servers.sites');
    Route::put('/servers/{server}/sites/settings', [ServerController::class, 'siteSettings'])->name('servers.sites.settings');

    Route::resource('domains', DomainController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('/domains/{domain}/refresh', [DomainController::class, 'refresh'])->middleware('throttle:6,1')->name('domains.refresh');
    Route::post('/domains/{domain}/monitor', [DomainController::class, 'monitor'])->name('domains.monitor');

    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('/incidents/{incident}/note', [IncidentController::class, 'note'])->name('incidents.note');
    Route::post('/incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
    Route::post('/incidents/{incident}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');

    Route::resource('channels', ChannelController::class)->except('show');
    Route::post('/channels/{channel}/test', [ChannelController::class, 'test'])->middleware('throttle:10,1')->name('channels.test');

    Route::resource('maintenance', MaintenanceController::class)->except('show');
    Route::resource('status-pages', StatusPageController::class)->except('show');

    Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
    Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
    Route::post('/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
    Route::put('/teams/{team}/members/{member}', [TeamController::class, 'updateMember'])->name('teams.members.update');
    Route::delete('/teams/{team}/members/{member}', [TeamController::class, 'removeMember'])->name('teams.members.destroy');

    Route::get('/monitors/{monitor}/snapshots/{snapshot}', [MonitorController::class, 'snapshot'])->name('monitors.snapshot');
    Route::post('/monitors/{monitor}/screenshot', [MonitorController::class, 'screenshot'])->middleware('throttle:3,1')->name('monitors.screenshot');

    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('billing.checkout');
    Route::get('/billing/invoices/{order}', [BillingController::class, 'invoice'])->name('billing.invoice');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::post('/profile/two-factor', [ProfileController::class, 'enableTwoFactor'])->name('profile.2fa.enable');
    Route::post('/profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.2fa.confirm');
    Route::delete('/profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('profile.2fa.disable');
    Route::post('/profile/tokens', [ProfileController::class, 'createToken'])->name('profile.tokens.store');
    Route::delete('/profile/tokens/{token}', [ProfileController::class, 'deleteToken'])->name('profile.tokens.destroy');

    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::resource('users', UserController::class)->except('show');
        Route::get('/probes', [ProbeController::class, 'index'])->name('probes.index');
        Route::post('/probes', [ProbeController::class, 'store'])->name('probes.store');
        Route::put('/probes/{probe}', [ProbeController::class, 'update'])->name('probes.update');
        Route::post('/probes/{probe}/token', [ProbeController::class, 'token'])->name('probes.token');
        Route::delete('/probes/{probe}', [ProbeController::class, 'destroy'])->name('probes.destroy');
        Route::get('/billing', [BillingController::class, 'admin'])->name('billing');
        Route::post('/billing/orders/{order}/paid', [BillingController::class, 'markPaid'])->name('billing.paid');
        Route::get('/system', [SystemController::class, 'index'])->name('system');
        Route::get('/audit', [SystemController::class, 'audit'])->name('audit');
    });
});
