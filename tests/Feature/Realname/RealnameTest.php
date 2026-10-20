<?php

namespace Tests\Feature\Realname;

use App\Http\Middleware\InitializePlugins;
use App\Models\Order;
use App\Models\Plan;
use App\Models\RealnameAttempt;
use App\Models\RealnameVerification;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Realname\AlipayIdentityProvider;
use App\Services\Realname\IdentityProvider;
use App\Services\Realname\RealnameException;
use App\Services\Realname\RealnamePolicy;
use App\Services\Realname\RealnameService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealnameTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdentityProvider $provider;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        // Override before ANY service provider boot: never use the developer's .env database.
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app['config']->set([
                'app.env' => 'testing',
                'app.key' => 'base64:' . base64_encode(str_repeat('t', 32)),
                'app.debug' => false,
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null,
                'cache.default' => 'array',
                'queue.default' => 'sync',
                'session.driver' => 'array',
                'mail.default' => 'array',
                'debugbar.enabled' => false,
                'realname.enabled' => true,
                'realname.require_purchase' => false,
                'realname.require_subscription' => false,
                'realname.hmac_key' => str_repeat('h', 32),
            ]);
        });
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->provider = new FakeIdentityProvider();
        $this->app->instance(IdentityProvider::class, $this->provider);
        $this->withoutMiddleware(InitializePlugins::class);
        Bus::fake();
    }

    private function user(): User
    {
        return User::withoutEvents(fn () => User::create([
            'email' => Str::uuid() . '@example.test',
            'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'balance' => 1000,
            'transfer_enable' => 1000,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'expired_at' => time() + 86400,
        ]));
    }

    private function start(User $user, string $key = 'test-idempotency-key-0001', array $extra = [])
    {
        Sanctum::actingAs($user);
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/user/realname/start', array_merge([
            'name' => '本地假测试',
            'id_number' => '110101199001010000',
            'consent' => true,
        ], $extra));
    }

    public function test_contract_and_idempotent_initialization_without_persisting_identity(): void
    {
        $user = $this->user();
        $first = $this->start($user)->assertOk()->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.attempt.status', 'pending');
        $id = $first->json('data.attempt.id');
        $this->start($user)->assertOk()->assertJsonPath('data.attempt.id', $id);
        $this->start($user, 'another-idempotency-key')->assertOk()->assertJsonPath('data.attempt.id', $id);
        $this->assertSame(1, $this->provider->initializations);
        $this->assertSame(1, RealnameAttempt::count());
        $raw = json_encode(DB::table('realname_attempts')->first());
        $this->assertStringNotContainsString('110101199001010000', $raw);
        $this->assertStringNotContainsString('本地假测试', $raw);
        $this->assertStringNotContainsString('fake-certify-id', $raw);
        $this->assertStringNotContainsString('https://openapi.alipay.com', $raw);
        $this->getJson('/api/v1/user/realname/status')->assertOk()->assertJsonPath('data.attempt.id', $id);
    }

    public function test_idempotency_payload_conflict_is_not_reinitialized(): void
    {
        $user = $this->user();
        $this->start($user)->assertOk();
        $this->start($user, 'test-idempotency-key-0001', ['name' => '另一个假测试'])
            ->assertStatus(409)->assertJsonPath('error_code', 'REALNAME_IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, $this->provider->initializations);
    }

    public function test_unknown_initialize_timeout_is_retained_and_never_blindly_retried(): void
    {
        $user = $this->user();
        $this->provider->initializeTimeout = true;
        $first = $this->start($user)->assertOk()->assertJsonPath('data.attempt.status', 'uncertain');
        $id = $first->json('data.attempt.id');
        $this->start($user, 'new-idempotency-key-0002')->assertOk()->assertJsonPath('data.attempt.id', $id);
        $this->postJson('/api/v1/user/realname/refresh', ['attempt_id' => $id])->assertOk()
            ->assertJsonPath('data.attempt.status', 'uncertain');
        $this->assertSame(1, $this->provider->initializations);
        $this->assertSame(0, $this->provider->queries);
        $this->assertNull(RealnameVerification::find($user->id)->verified_at);
    }

    public function test_refresh_requires_ownership_and_only_query_can_mark_verified(): void
    {
        $owner = $this->user();
        $id = $this->start($owner)->json('data.attempt.id');
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/user/realname/refresh', ['attempt_id' => $id])->assertStatus(404);
        $this->assertSame(0, $this->provider->queries);
        Sanctum::actingAs($owner);
        // Forged browser return values are ignored, including passed and user_id.
        $this->getJson('/api/v1/user/realname/status?passed=T&user_id=999')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->provider->queryResult = 'passed';
        $response = $this->postJson('/api/v1/user/realname/refresh', ['attempt_id' => $id, 'passed' => false]);
        $response->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.attempt.status', 'succeeded')
            ->assertJsonPath('data.attempt.auth_url', null);
        $this->assertNotNull($response->json('data.verified_at'));
        $this->postJson('/api/v1/user/realname/refresh', ['attempt_id' => $id])->assertOk();
        $this->assertSame(1, $this->provider->queries);
    }

    public function test_query_unknown_and_outage_are_not_terminal_even_after_local_link_expiry(): void
    {
        $user = $this->user();
        $id = $this->start($user)->json('data.attempt.id');
        $this->travel(31)->minutes();
        $this->provider->queryTimeout = true;
        $this->postJson('/api/v1/user/realname/refresh', ['attempt_id' => $id])->assertOk()
            ->assertJsonPath('data.attempt.status', 'uncertain')->assertJsonPath('data.attempt.auth_url', null);
        $this->travel(11)->seconds();
        $this->provider->queryTimeout = false;
        $this->provider->queryResult = 'passed';
        $this->postJson('/api/v1/user/realname/refresh', ['attempt_id' => $id])->assertOk()->assertJsonPath('data.status', 'verified');
    }

    public function test_known_failure_and_expiry_are_terminal(): void
    {
        foreach (['failed', 'expired'] as $result) {
            $user = $this->user();
            $id = $this->start($user)->json('data.attempt.id');
            $this->provider->queryResult = $result;
            $this->postJson('/api/v1/user/realname/refresh', ['attempt_id' => $id])->assertOk()
                ->assertJsonPath('data.status', $result)->assertJsonPath('data.attempt.status', $result);
        }
    }

    public function test_refresh_query_is_serialized_and_throttled(): void
    {
        $user = $this->user();
        $id = $this->start($user)->json('data.attempt.id');
        $this->postJson('/api/v1/user/realname/refresh', ['attempt_id' => $id])->assertOk();
        $this->postJson('/api/v1/user/realname/refresh', ['attempt_id' => $id])->assertOk();
        $this->assertSame(1, $this->provider->queries);
    }

    public function test_concurrent_start_reentry_with_different_key_observes_reserved_attempt(): void
    {
        $user = $this->user();
        $this->provider->onInitialize = function () use ($user) {
            $snapshot = app(RealnameService::class)->start($user, '本地假测试', '110101199001010000', 'concurrent-key-0002');
            $this->assertSame('initializing', $snapshot['attempt']['status']);
        };
        $this->start($user)->assertOk();
        $this->assertSame(1, $this->provider->initializations);
        $this->assertSame(1, RealnameAttempt::count());
    }

    public function test_switches_fail_closed_and_verified_uses_local_state(): void
    {
        $user = $this->user();
        config(['realname.enabled' => false]);
        $this->start($user)->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame(0, $this->provider->initializations);
        app(RealnamePolicy::class)->purchase($user);
        config(['realname.require_purchase' => true]);
        try {
            app(RealnamePolicy::class)->purchase($user);
            $this->fail('Disabled provider must not bypass purchase enforcement');
        } catch (RealnameException $e) {
            $this->assertSame('REALNAME_REQUIRED', $e->errorCode);
        }
        RealnameVerification::create(['user_id' => $user->id, 'verified_at' => now()]);
        app(RealnamePolicy::class)->purchase($user);
        $this->assertSame(0, $this->provider->queries);
    }

    public function test_subscription_routes_gate_before_output_and_credential_rotation_but_login_stays_valid(): void
    {
        $user = $this->user();
        $uuid = $user->uuid;
        $token = $user->token;
        Sanctum::actingAs($user);
        config(['realname.require_subscription' => true]);
        foreach (['/api/v1/user/getSubscribe', '/api/v1/user/resetSecurity', '/api/v2/user/resetSecurity', '/api/v1/user/server/fetch', '/api/v1/client/subscribe?token=' . $token] as $url) {
            $this->getJson($url)->assertStatus(403)->assertJsonPath('error_code', 'REALNAME_REQUIRED');
        }
        $user->refresh();
        $this->assertSame($uuid, $user->uuid);
        $this->assertSame($token, $user->token);
        $this->getJson('/api/v1/user/checkLogin')->assertOk()->assertJsonPath('data.is_login', true);
    }

    public function test_free_old_checkout_and_new_purchase_are_blocked_before_balance_or_fulfillment(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => 1, 'type' => 1, 'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Str::random(30), 'total_amount' => 0, 'status' => Order::STATUS_PENDING,
        ]);
        config(['realname.require_purchase' => true]);
        $this->postJson('/api/v1/user/order/checkout', ['trade_no' => $order->trade_no])->assertStatus(403)
            ->assertJsonPath('error_code', 'REALNAME_REQUIRED');
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        try {
            OrderService::createFromRequest($user, new Plan(), Plan::PERIOD_MONTHLY);
            $this->fail('Purchase must be blocked');
        } catch (RealnameException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame(1000, $user->fresh()->balance);
    }

    public function test_historical_paid_callback_state_transition_is_not_realname_gated(): void
    {
        $user = $this->user();
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => 1, 'type' => 1, 'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Str::random(30), 'total_amount' => 100, 'status' => Order::STATUS_PENDING,
        ]);
        config(['realname.require_purchase' => true, 'realname.require_subscription' => true]);
        $this->assertTrue((new OrderService($order))->paid('local-fake-verified-callback'));
        $this->assertTrue((new OrderService($order))->paid('local-fake-verified-callback'));
        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);
        Bus::assertDispatchedSync(\App\Jobs\OrderHandleJob::class, 1);
    }

    public function test_input_validation_and_start_rate_limit_do_not_call_provider(): void
    {
        $user = $this->user();
        $this->start($user, 'test-idempotency-key-0001', ['consent' => 'true'])->assertStatus(422);
        $this->assertSame(0, $this->provider->initializations);
        for ($i = 0; $i < 4; $i++) {
            $this->start($user)->assertOk();
        }
        $this->start($user)->assertStatus(429);
    }

    public function test_initialize_ambiguous_acceptance_is_not_success_even_with_certify_id(): void
    {
        $this->provider->accepted = null;
        $this->start($this->user())->assertOk()->assertJsonPath('data.attempt.status', 'uncertain')
            ->assertJsonPath('data.attempt.auth_url', null);
    }

    public function test_daily_quota_and_cooldown_apply_before_new_provider_initialization(): void
    {
        $user = $this->user();
        $this->provider->accepted = false;
        config(['realname.daily_user_limit' => 1]);
        $this->start($user)->assertOk()->assertJsonPath('data.attempt.status', 'failed');
        $this->start($user, 'test-idempotency-key-0002')->assertStatus(429);
        $this->travel(61)->seconds();
        $this->start($user, 'test-idempotency-key-0003')->assertStatus(429);
        $this->assertSame(1, $this->provider->initializations);
    }

    public function test_daily_ip_quota_is_shared_across_accounts(): void
    {
        config(['realname.daily_ip_limit' => 1]);
        $this->start($this->user())->assertOk();
        $this->start($this->user())->assertStatus(429);
        $this->assertSame(1, $this->provider->initializations);
    }

    public function test_knowledge_only_gates_credential_bearing_content(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        config(['realname.require_subscription' => true]);
        foreach (['普通帮助内容' => 200, '{{subscribeUrl}}' => 403, '{{urlEncodeSubscribeUrl}}' => 403, '{{safeBase64SubscribeUrl}}' => 403] as $body => $status) {
            $id = DB::table('v2_knowledge')->insertGetId([
                'language' => 'zh-CN', 'category' => 'help', 'title' => 'local fake', 'body' => $body,
                'show' => 1, 'created_at' => time(), 'updated_at' => time(),
            ]);
            $this->getJson('/api/v1/user/knowledge/fetch?id=' . $id)->assertStatus($status);
        }
    }

    public function test_gift_card_subscription_reward_is_gated_but_balance_top_up_is_not(): void
    {
        $user = $this->user();
        // General traffic gifts require an existing plan before reaching redemption.
        $user->plan_id = 1;
        $user->save();
        Sanctum::actingAs($user);
        config(['realname.require_purchase' => true]);
        foreach ([['balance' => 100], ['transfer_enable' => 100]] as $rewards) {
            $template = \App\Models\GiftCardTemplate::create([
                'name' => 'local fake', 'description' => 'test', 'type' => \App\Models\GiftCardTemplate::TYPE_GENERAL,
                'status' => 1, 'rewards' => $rewards, 'admin_id' => 1,
            ]);
            $code = \App\Models\GiftCardCode::create([
                'template_id' => $template->id, 'code' => Str::upper(Str::random(16)),
                'status' => \App\Models\GiftCardCode::STATUS_UNUSED, 'usage_count' => 0, 'max_usage' => 1,
            ]);
            $response = $this->postJson('/api/v1/user/gift-card/redeem', ['code' => $code->code]);
            if (isset($rewards['balance'])) {
                $response->assertOk();
                $this->assertSame(1100, $user->fresh()->balance);
            } else {
                $response->assertStatus(403)->assertJsonPath('error_code', 'REALNAME_REQUIRED');
                $this->assertSame(0, $code->fresh()->usage_count);
            }
        }
    }

    public function test_unauthenticated_status_is_401_not_a_business_gate(): void
    {
        $this->getJson('/api/v1/user/realname/status')->assertStatus(401);
    }

    public function test_return_page_never_marks_verification_from_query_parameters(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $this->get('/realname/return?success=true&passed=T&user_id=' . $user->id)
            ->assertOk()
            ->assertSee('请返回 WAF加速器')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertFalse(RealnameVerification::where('user_id', $user->id)->exists());
        $this->assertSame(0, $this->provider->queries);
    }

    public function test_alipay_query_string_flags_and_urls_are_strict(): void
    {
        $this->assertSame('passed', AlipayIdentityProvider::interpretQuery('10000', 'T', null));
        $this->assertSame('unknown', AlipayIdentityProvider::interpretQuery('10000', 'F', null));
        foreach (['', 'true', 'false', '1', 't'] as $value) {
            $this->assertSame('unknown', AlipayIdentityProvider::interpretQuery('10000', $value, null));
        }
        $this->assertSame('unknown', AlipayIdentityProvider::interpretQuery('40004', 'T', 'SYSTEM_ERROR'));
        $this->assertSame('expired', AlipayIdentityProvider::interpretQuery('40004', null, 'CERTIFY_ID_EXPIRED'));
        $this->assertTrue(RealnameService::isAllowedAuthUrl('https://openapi.alipay.com/gateway.do?sign=fake'));
        foreach (['http://openapi.alipay.com/gateway.do', 'https://openapi.alipay.com.evil.test/gateway.do', 'https://user@openapi.alipay.com/gateway.do', 'https://openapi.alipay.com:444/gateway.do', 'javascript:alert(1)'] as $url) {
            $this->assertFalse(RealnameService::isAllowedAuthUrl($url));
        }
    }
}

class FakeIdentityProvider implements IdentityProvider
{
    public int $initializations = 0;
    public int $queries = 0;
    public bool $initializeTimeout = false;
    public ?bool $accepted = true;
    public bool $queryTimeout = false;
    public string $queryResult = 'unknown';
    public $onInitialize;

    public function assertReady(): void {}

    public function initialize(string $outerOrderNo, #[\SensitiveParameter] string $name, #[\SensitiveParameter] string $idNumber): array
    {
        $this->initializations++;
        if ($this->onInitialize) {
            ($this->onInitialize)();
        }
        if ($this->initializeTimeout) {
            throw new \RuntimeException('local fake timeout');
        }
        return ['accepted' => $this->accepted, 'certify_id' => 'fake-certify-id-' . $outerOrderNo];
    }

    public function certify(#[\SensitiveParameter] string $certifyId): string
    {
        return 'https://openapi.alipay.com/gateway.do?sign=local-fake';
    }

    public function query(#[\SensitiveParameter] string $certifyId): string
    {
        $this->queries++;
        if ($this->queryTimeout) {
            throw new \RuntimeException('local fake timeout');
        }
        return $this->queryResult;
    }
}
