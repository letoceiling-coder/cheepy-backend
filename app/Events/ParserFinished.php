<?php

namespace App\Events;

use App\Models\ParserJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParserFinished implements ShouldBroadcast
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
        return 'ParserFinished';
    }

    public function broadcastWith(): array
    {
        return [
            'job' => $this->job->formatForBroadcast(),
            'status' => $this->job->status,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
