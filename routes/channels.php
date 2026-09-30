<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\Chat\Models\Conversation;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('conversation.{conversationId}', function ($user, string $conversationId) {
    $conversation = Conversation::find($conversationId);
    if (! $conversation) {
        return false;
    }
    // Admin chat inbox can subscribe to any conversation, matching its API access.
    if ($user instanceof \Modules\Admin\Models\Admin) {
        return true;
    }
    // Client (Modules\Client\Models\Client)
    if ($user instanceof \Modules\Client\Models\Client) {
        return (int) $user->id === (int) $conversation->client_id;
    }
    // Vendor employee (Modules\Vendor\Models\VendorEmployee)
    if ($user instanceof \Modules\Vendor\Models\VendorEmployee) {
        return $conversation->vendor_id !== null && (int) $user->vendor_id === (int) $conversation->vendor_id;
    }
    // Driver (Modules\Driver\Models\Driver)
    if ($user instanceof \Modules\Driver\Models\Driver) {
        return $conversation->driver_id !== null && (int) $user->id === (int) $conversation->driver_id;
    }

    return false;
});
