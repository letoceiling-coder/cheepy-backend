<?php

namespace App\Listeners;

use App\Events\ParserFinished;
use Illuminate\Support\Facades\Redis;

class ReleaseParserLockOnFinished
{
    public function handle(ParserFinished $event): void
    {
        try {
            Redis::del('parser_lock');
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
