<?php

namespace Tests\Feature\Acceleration;

use App\Services\Acceleration\ReleaseService;
use Laravel\Sanctum\Sanctum;

class AccelerationApiTest extends AccelerationScenarioTestCase
{
    public function test_prepare_and_poll_do_not_publish_without_node_evidence(): void
    {
        Sanctum::actingAs($this->user);
        $this->withHeader('Idempotency-Key', 'http-test-key-0001')->postJson('/api/v1/user/acceleration/prepare', ['application_ids' => ['demo'], 'expected_version' => 0])
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/v1/user/acceleration/state')->assertOk()->assertJsonPath('data.release.status', 'pending');
        $this->getJson('/api/v1/user/acceleration/config')->assertStatus(503)->assertJsonPath('error_code', 'CONFIG_NOT_READY');
    }

    public function test_realname_gate_precedes_even_matching_etag(): void
    {
        Sanctum::actingAs($this->user);
        admin_setting(['real_name_verify' => 1]);
        $this->withHeader('If-None-Match', 'old')->getJson('/api/v1/user/acceleration/config')
            ->assertStatus(403)->assertJsonPath('error_code', 'REALNAME_REQUIRED');
    }

    public function test_catalog_exposes_rules_but_no_proxy_credentials(): void
    {
        Sanctum::actingAs($this->user);
        $response = $this->getJson('/api/v1/user/acceleration/catalog')->assertOk()->assertJsonPath('data.applications.0.id', 'demo');
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString($this->user->uuid, $response->getContent());
    }

    public function test_unauthenticated_config_is_unauthorized(): void
    {
        $this->getJson('/api/v1/user/acceleration/config')->assertUnauthorized();
    }

    public function test_expired_account_is_rejected_before_etag(): void
    {
        Sanctum::actingAs($this->user);
        \Illuminate\Support\Facades\DB::table('v2_user')->where('id',$this->user->id)->update(['expired_at'=>time()-1]);
        $this->withHeader('If-None-Match','old')->getJson('/api/v1/user/acceleration/config')->assertStatus(403)->assertJsonPath('error_code','ACCOUNT_UNAVAILABLE');
    }
}
