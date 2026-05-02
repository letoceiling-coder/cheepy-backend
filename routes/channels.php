<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
| parser - public channel for parser status updates
| marketplace - public storefront operational settings updates
*/

Broadcast::channel('parser', function () {
    return true;
});

Broadcast::channel('marketplace', function () {
    return true;
});
