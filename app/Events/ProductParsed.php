<?php

namespace App\Events;

use App\Models\ParserJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductParsed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ParserJob $job,
        public array $product
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('parser')];
    }

    public function broadcastAs(): string
    {
        return 'ProductParsed';
    }

    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->id,
            'job' => $this->job->formatForBroadcast(),
            'product' => $this->product,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
