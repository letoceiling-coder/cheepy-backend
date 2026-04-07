<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_json(): void
    {
        $response = $this->getJson('/api/v1/health');
        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'database',
            'redis',
            'parser_last_run',
            'timestamp',
        ]);
    }

    public function test_health_database_connected(): void
    {
        $this->assertNotNull(DB::connection()->getPdo());
    }
}
