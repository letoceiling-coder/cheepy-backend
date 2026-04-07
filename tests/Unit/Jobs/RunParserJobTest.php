<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RunParserJob;
use App\Models\ParserJob;
use App\Services\DatabaseParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RunParserJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_execution_calls_parser_service(): void
    {
        $job = ParserJob::factory()->create([
            'status' => 'pending',
            'type' => 'menu_only',
        ]);

        $mock = Mockery::mock('overload:'.DatabaseParserService::class);
        $mock->shouldReceive('__construct')->once()->with(Mockery::type(ParserJob::class));
        $mock->shouldReceive('run')->once()->withNoArgs();

        $runJob = new RunParserJob($job->id);
        $runJob->handle();

        $job->refresh();
        $this->assertContains($job->status, ['completed', 'running']);
    }

    public function test_job_handles_missing_parser_job(): void
    {
        $runJob = new RunParserJob(99999);
        $runJob->handle();
    }

    public function test_job_has_retry_config(): void
    {
        $runJob = new RunParserJob(1);
        $this->assertEquals(3, $runJob->tries);
        $this->assertEquals(3600, $runJob->timeout);
        $this->assertEquals([60, 300, 900], $runJob->backoff);
    }
}
