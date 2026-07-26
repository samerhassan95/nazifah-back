<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\Api\V1\User\ChatController;

Route::prefix('v1/user')->middleware(['auth:client', 'banned'])->group(function () {
    Route::prefix('chat')->group(function () {
        // Send message (conversation_id → existing; order_id → find/create order chat; neither → support)
        Route::post('/send', [ChatController::class, 'sendMessage'])
            ->name('user.chat.send');

        // Get all conversations
        Route::get('/conversations', [ChatController::class, 'getConversations'])
            ->name('user.chat.conversations');

        // Get messages by conversation
        Route::get('/conversations/{conversationId}/messages', [ChatController::class, 'getMessages'])
            ->name('user.chat.messages');
    });
});
