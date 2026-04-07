<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParserState extends Model
{
    protected $table = 'parser_state';

    protected $fillable = ['status', 'network_mode', 'locked', 'last_start', 'last_stop'];

    protected $casts = [
        'locked' => 'boolean',
        'last_start' => 'datetime',
        'last_stop' => 'datetime',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_PAUSED_NETWORK = 'paused_network';

    /** Get singleton row (id=1). */
    public static function current(): self
    {
        $row = self::first();
        if (!$row) {
            $row = self::create([
                'status' => self::STATUS_STOPPED,
                'locked' => false,
            ]);
        }
        return $row;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }

    public function isPaused(): bool
    {
        return in_array($this->status, [self::STATUS_PAUSED, self::STATUS_PAUSED_NETWORK], true);
    }
}
