<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AiMetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_metrics_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/admin/ai/metrics');

        $response->assertStatus(401);
    }

    public function test_ai_metrics_returns_structure_with_admin_jwt(): void
    {
        DB::table('ai_metrics')->insert([
            [
                'date' => '2026-03-19',
                'algorithm_version' => 'v1',
                'total_predictions' => 100,
                'overrides' => 20,
                'avg_confidence' => 84.5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'date' => '2026-03-19',
                'algorithm_version' => 'v2',
                'total_predictions' => 80,
                'overrides' => 8,
                'avg_confidence' => 88.0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);
        $login->assertOk()->assertJsonStructure(['token']);

        $token = (string) $login->json('token');
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/ai/metrics');

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => [
                    'algorithm_version',
                    'total_predictions',
                    'overrides',
                    'avg_confidence',
                    'override_rate',
                ],
            ],
        ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertCount(2, $data);

        $byVersion = collect($data)->keyBy('algorithm_version');
        $this->assertArrayHasKey('v1', $byVersion->toArray());
        $this->assertArrayHasKey('v2', $byVersion->toArray());

        $this->assertEquals(100, $byVersion['v1']['total_predictions']);
        $this->assertEquals(20, $byVersion['v1']['overrides']);

        $this->assertEquals(80, $byVersion['v2']['total_predictions']);
        $this->assertEquals(8, $byVersion['v2']['overrides']);

        $this->assertEqualsWithDelta(0.2, (float) $byVersion['v1']['override_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.1, (float) $byVersion['v2']['override_rate'], 0.0001);

        $this->assertEqualsWithDelta(84.5, (float) $byVersion['v1']['avg_confidence'], 0.001);
        $this->assertEqualsWithDelta(88.0, (float) $byVersion['v2']['avg_confidence'], 0.001);

        $this->assertIsNumeric($byVersion['v1']['avg_confidence']);
        $this->assertIsNumeric($byVersion['v1']['override_rate']);
    }
}

