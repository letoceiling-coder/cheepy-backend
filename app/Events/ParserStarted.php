<?php

namespace App\Events;

use App\Models\ParserJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParserStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ParserJob $job
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('parser')];
    }

    public function broadcastAs(): string
    {
        return 'ParserStarted';
    }

    public function broadcastWith(): array
    {
        return [
            'job' => $this->job->formatForBroadcast(),
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
