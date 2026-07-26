<?php

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\Http;
use Modules\Chat\Models\Message;

class FirebaseService
{
    private string $firebaseUrl;

    private string $serverKey;

    public function __construct()
    {
        $this->firebaseUrl = config('chat.firebase_url');
        $this->serverKey = config('chat.firebase_server_key');
    }

    public function sendMessageToFirebase(string $conversationId, Message $message): bool
    {
        try {
            $firebasePath = "conversations/{$conversationId}/messages/{$message->id}";

            $data = [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'sender_type' => $message->sender_type,
                'sender_id' => $message->sender_id,
                'message' => $message->message,
                'type' => $message->type,
                'file_url' => $message->file_url,
                'is_read' => $message->is_read,
                'created_at' => $message->created_at->toIso8601String(),
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->serverKey,
                'Content-Type' => 'application/json',
            ])->put("{$this->firebaseUrl}/{$firebasePath}.json", $data);

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function updateConversation(string $conversationId, array $data): bool
    {
        try {
            $firebasePath = "conversations/{$conversationId}";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->serverKey,
                'Content-Type' => 'application/json',
            ])->patch("{$this->firebaseUrl}/{$firebasePath}.json", $data);

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function markMessagesAsRead(string $conversationId, array $messageIds): bool
    {
        try {
            foreach ($messageIds as $messageId) {
                $firebasePath = "conversations/{$conversationId}/messages/{$messageId}";

                Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->serverKey,
                    'Content-Type' => 'application/json',
                ])->patch("{$this->firebaseUrl}/{$firebasePath}.json", [
                    'is_read' => true,
                    'read_at' => now()->toIso8601String(),
                ]);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
