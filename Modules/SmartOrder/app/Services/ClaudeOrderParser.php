<?php

namespace Modules\SmartOrder\Services;

use Anthropic\Client;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Models\AdminSetting;

/**
 * Wraps the Claude API to turn free-text/voice-transcribed order text into
 * structured draft lines grounded against a specific branch's real catalog
 * (piece_id/service_id are always picked from the catalog we hand it, or
 * null with candidates when ambiguous — never invented).
 */
class ClaudeOrderParser
{
    private const MODEL = 'claude-opus-5-5';

    /**
     * @param  array<int, array{role: string, text: string}>  $history  Full conversation so far, oldest first.
     * @param  array<int, array<string, mixed>>  $currentLines  The draft's current lines (for continuity — "زوّد قميص" etc.), empty on the first turn.
     * @param  array<int, array<string, mixed>>  $catalog  [{id, name_ar, name_en, services: [{id, name_ar, name_en}]}]
     * @return array{lines: array, reply: string, not_understood: bool}|null null on API failure
     */
    public function parse(array $history, array $currentLines, array $catalog, string $locale): ?array
    {
        $apiKey = AdminSetting::getValue('smart_order_claude_api_key') ?: env('ANTHROPIC_API_KEY');
        if (! $apiKey) {
            Log::error('Smart order: no Claude API key configured (AdminSetting smart_order_claude_api_key / ANTHROPIC_API_KEY)');

            return null;
        }

        $client = new Client(apiKey: $apiKey);

        $messages = array_map(
            fn (array $m) => ['role' => $m['role'], 'content' => $m['text']],
            $history
        );

        try {
            $message = $client->messages->create(
                model: self::MODEL,
                maxTokens: 4096,
                system: $this->buildSystemPrompt($currentLines, $catalog, $locale),
                messages: $messages,
                outputConfig: ['format' => $this->outputSchema()],
            );
        } catch (\Throwable $e) {
            Log::error('Smart order: Claude API call failed', ['error' => $e->getMessage()]);

            return null;
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $decoded = json_decode($block->text, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    private function buildSystemPrompt(array $currentLines, array $catalog, string $locale): string
    {
        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE);
        $currentLinesJson = json_encode($currentLines, JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are the backend of a laundry delivery app's "smart order" chat. The
client writes (or dictates, transcribed to text) their order in casual
Arabic or English — e.g. "عايز اغسل ٣ قمصان وبدلة وكوي بنطلونين". Your job
is to turn that into structured order lines, grounded ONLY in the real
catalog below — never invent a piece_id or service_id that isn't in it.

Catalog for the selected laundry branch (the only valid pieces/services):
{$catalogJson}

Current draft lines before this message (empty on the first turn — update
these based on the new message: add, clarify, remove, or change quantity;
don't silently drop a line the user didn't ask to remove):
{$currentLinesJson}

For each line in your response:
- "line_key": keep the SAME value as in current draft lines if this is the
  same item being refined/clarified; use a new string like "new_1" for a
  genuinely new item. (The server reassigns final keys — just keep intent clear.)
- "raw_text": the user's own words for this line.
- "status": "resolved" (real piece_id AND service_id chosen, quantity known),
  "ambiguous" (piece OR service needs a human pick — set ambiguous_field and
  up to 4 candidate ids from the catalog), or "unknown" (the described item
  has NO reasonable match in this catalog at all).
- "piece_id"/"service_id": the catalog id, or null if not yet resolved.
- "quantity": integer, default 1 if not stated.
- NEVER pick a default service on your own — if the client didn't say which
  service (wash/iron/dry-clean/etc.) for a resolved piece, mark it
  "ambiguous" with ambiguous_field "service" and candidate_service_ids from
  that piece's available services (max 4; if more than 4 exist pick the 4
  most likely given the user's words).
- If ambiguous on "piece", candidate_piece_ids are catalog piece ids that
  could match what the user described (max 4).

"reply": a short, natural {$locale} reply to show in the chat — confirm what
you understood, ask the one most important open question if any (there can
only be one open question per turn, in priority: piece > service > quantity
doesn't need asking, just default to 1), and if any line is "unknown"
(item not offered by this laundry at all), say so plainly in the reply so
the client knows it won't be included.

"not_understood": true ONLY if you could not extract any order-like line at
all from the message (e.g. pure small talk, or truly nonsensical input) —
in that case "lines" should be empty and "reply" should ask the client to
rephrase what they want cleaned.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function outputSchema(): array
    {
        return [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'lines' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'line_key' => ['type' => 'string'],
                                'raw_text' => ['type' => 'string'],
                                'status' => ['type' => 'string', 'enum' => ['resolved', 'ambiguous', 'unknown']],
                                'piece_id' => ['type' => ['integer', 'null']],
                                'service_id' => ['type' => ['integer', 'null']],
                                'quantity' => ['type' => 'integer'],
                                'ambiguous_field' => ['type' => ['string', 'null'], 'enum' => ['piece', 'service', null]],
                                'candidate_piece_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                'candidate_service_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            ],
                            'required' => [
                                'line_key', 'raw_text', 'status', 'piece_id', 'service_id',
                                'quantity', 'ambiguous_field', 'candidate_piece_ids', 'candidate_service_ids',
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'reply' => ['type' => 'string'],
                    'not_understood' => ['type' => 'boolean'],
                ],
                'required' => ['lines', 'reply', 'not_understood'],
                'additionalProperties' => false,
            ],
        ];
    }
}
