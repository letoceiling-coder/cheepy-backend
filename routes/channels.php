<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
| parser - public channel for parser status updates
*/

Broadcast::channel('parser', function () {
    return true;
});
