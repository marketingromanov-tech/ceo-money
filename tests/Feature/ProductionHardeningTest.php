<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthenticatedMutationLimiter;
use App\Support\AdminNotificationTarget;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_https_response_has_security_headers_and_local_does_not_get_hsts(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set(['security.headers_enabled' => true, 'security.csp_report_only' => true]);
        $response = $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])->get('https://localhost/login');
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNull($this->withServerVariables(['HTTPS' => 'off', 'SERVER_PORT' => 80])->get('http://localhost/login')->headers->get('Strict-Transport-Security'));
    }

    public function test_exact_configured_proxy_is_trusted_after_configuration_is_cached(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set([
            'security.headers_enabled' => true,
            'security.trusted_proxies' => ['10.20.30.40'],
        ]);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.20.30.40', 'HTTPS' => 'off', 'SERVER_PORT' => 80])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->get('http://staging.example/login');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_notification_targets_are_derived_from_allowlisted_event_types(): void
    {
        $this->assertSame(route('admin.deposits.index', ['deposit' => 42]), AdminNotificationTarget::resolve(['event' => 'deposit.created', 'entity_id' => 42, 'target' => 'javascript:alert(1)']));
        $this->assertSame(route('admin.withdrawals.index', ['withdrawal' => 9]), AdminNotificationTarget::resolve(['event' => 'withdrawal.created', 'entity_id' => 9, 'target' => '//evil.example']));
        $this->assertSame(route('admin.notifications.index'), AdminNotificationTarget::resolve(['event' => 'unknown', 'target' => 'https://evil.example']));
    }

    public function test_authenticated_mutation_limits_are_scoped_per_investor_and_admin_is_not_blocked(): void
    {
        $first = User::factory()->create(['role' => 'investor']);
        $second = User::factory()->create(['role' => 'investor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withSession(['mutation_rate_limit_test' => true]);
        $limiter = app(AuthenticatedMutationLimiter::class);
        for ($i = 0; $i < 5; $i++) $limiter->hit('deposit', $first);
        try { $limiter->hit('deposit', $first); $this->fail('Expected throttle.'); }
        catch (DomainException $exception) { $this->assertStringContainsString('Слишком много', $exception->getMessage()); }
        $limiter->hit('deposit', $second);
        for ($i = 0; $i < 20; $i++) $limiter->hit('deposit', $admin);
        $this->addToAssertionCount(2);
    }

    public function test_production_check_is_read_only_and_reports_local_blockers(): void
    {
        $this->artisan('app:production-check')->assertFailed()->expectsOutputToContain('[BLOCK] APP_ENV');
    }

    public function test_production_check_rejects_wildcard_proxy_and_missing_frontend_manifest(): void
    {
        config(['security.trusted_proxies' => ['*'], 'production.frontend_manifest' => public_path('missing-production-manifest.json')]);
        $this->artisan('app:production-check')->assertFailed()
            ->expectsOutputToContain('[BLOCK] Trusted proxies wildcard')
            ->expectsOutputToContain('[BLOCK] Frontend manifest');
    }

    public function test_production_check_validates_database_session_table_without_destructive_queries(): void
    {
        config(['session.driver' => 'database', 'session.table' => 'missing_sessions_table']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = strtolower($query->sql); });

        $this->artisan('app:production-check')->assertFailed()->expectsOutputToContain('[BLOCK] Session table');

        $sql = implode("\n", $queries);
        $this->assertDoesNotMatchRegularExpression('/\b(drop|truncate|delete|update|insert|alter|create)\b/', $sql);
    }

    public function test_database_seeder_never_creates_demo_data_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        (new \Database\Seeders\DatabaseSeeder())->run();
        $this->assertDatabaseCount('users', 0);
    }
}
