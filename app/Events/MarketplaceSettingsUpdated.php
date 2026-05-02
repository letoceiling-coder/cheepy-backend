<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MarketplaceSettingsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $settings
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('marketplace')];
    }

    public function broadcastAs(): string
    {
        return 'MarketplaceSettingsUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'settings' => $this->settings,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
