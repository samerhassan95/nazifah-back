<?php

namespace Modules\SmartOrder\Services;

use App\Services\DistanceService;
use App\Services\UploadFilesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Admin\Models\AdminSetting;
use Modules\Branch\Models\Branch;
use Modules\Client\Models\Client;
use Modules\Order\Models\Order;
use Modules\Order\Services\CartService;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\SmartOrder\Models\SmartOrderConversation;

class SmartOrderConversationService
{
    private const MAX_TEXT_LENGTH = 1000;

    public function __construct(
        private ClaudeOrderParser $claudeParser,
        private CartService $cartService,
        private DistanceService $distanceService,
        private UploadFilesService $uploadFilesService,
    ) {}

    /**
     * @return array{success: bool, status: int, message?: string, data?: array}
     */
    public function sendMessage(Client $client, ?int $conversationId, string $text, string $locale): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'status' => 400, 'message' => __('smart_order.text_required')];
        }
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return ['success' => false, 'status' => 400, 'message' => __('smart_order.text_too_long')];
        }

        $rateLimit = $this->checkDailyLimit($client);
        if (! $rateLimit['allowed']) {
            return ['success' => false, 'status' => 429, 'message' => __('smart_order.daily_limit_reached')];
        }

        if ($conversationId === null) {
            $conversation = SmartOrderConversation::create([
                'client_id' => $client->id,
                'status' => SmartOrderConversation::STATUS_NEEDS_CLARIFICATION,
                'messages' => [],
                'draft_lines' => [],
                'last_activity_at' => now(),
            ]);
        } else {
            $conversation = SmartOrderConversation::where('client_id', $client->id)->find($conversationId);
            if (! $conversation) {
                return ['success' => false, 'status' => 404, 'message' => __('smart_order.conversation_not_found')];
            }
            if ($conversation->isExpired()) {
                return ['success' => false, 'status' => 404, 'message' => __('smart_order.conversation_expired')];
            }
        }

        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'text' => $text];
        $conversation->messages = $messages;

        $this->incrementDailyCount($client);

        return $this->advance($conversation, $locale);
    }

    /**
     * @return array{success: bool, status: int, message?: string, data?: array}
     */
    public function resolve(Client $client, int $conversationId, string $lineKey, string $field, int $optionId, string $locale): array
    {
        $conversation = SmartOrderConversation::where('client_id', $client->id)->find($conversationId);
        if (! $conversation) {
            return ['success' => false, 'status' => 404, 'message' => __('smart_order.conversation_not_found')];
        }
        if ($conversation->isExpired()) {
            return ['success' => false, 'status' => 404, 'message' => __('smart_order.conversation_expired')];
        }

        $question = $conversation->pending_question;
        $validOptionIds = collect($question['options'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($question === null || $question['field'] !== $field || ! in_array($optionId, $validOptionIds, true)) {
            return ['success' => false, 'status' => 400, 'message' => __('smart_order.option_not_found')];
        }

        if ($field === 'laundry') {
            $branch = Branch::with('vendor')->find($optionId);
            if (! $branch) {
                return ['success' => false, 'status' => 400, 'message' => __('smart_order.option_not_found')];
            }

            $conversation->vendor_id = $branch->vendor_id;
            $conversation->branch_id = $branch->id;
            $conversation->laundry_selection = SmartOrderConversation::SELECTION_PICKED;
            $label = $branch->vendor?->getTranslation('name', $locale) ?? $branch->getTranslation('name', $locale);
            $messages = $conversation->messages ?? [];
            $messages[] = ['role' => 'user', 'text' => $label];
            $conversation->messages = $messages;

            return $this->advance($conversation, $locale);
        }

        // field is 'piece' or 'service' — a deterministic draft-line mutation, no Claude call needed.
        $lines = $conversation->draft_lines ?? [];
        $lineIndex = null;
        foreach ($lines as $i => $line) {
            if (($line['line_key'] ?? null) === $lineKey) {
                $lineIndex = $i;
                break;
            }
        }
        if ($lineIndex === null) {
            return ['success' => false, 'status' => 400, 'message' => __('smart_order.line_not_found')];
        }

        if ($field === 'piece') {
            $piece = Piece::find($optionId);
            $lines[$lineIndex]['piece_id'] = $optionId;
            $lines[$lineIndex]['raw_text'] = $piece?->getTranslation('name', $locale) ?? $lines[$lineIndex]['raw_text'];
        } else {
            $lines[$lineIndex]['service_id'] = $optionId;
        }

        $branchId = (int) $conversation->branch_id;
        $pieceId = $lines[$lineIndex]['piece_id'] ?? null;
        $serviceId = $lines[$lineIndex]['service_id'] ?? null;

        if ($pieceId && ! $serviceId) {
            $serviceOptions = $this->servicesForPiece((int) $pieceId, $branchId);
            $lines[$lineIndex]['status'] = 'ambiguous';
            $lines[$lineIndex]['ambiguous_field'] = 'service';
            $lines[$lineIndex]['candidate_service_ids'] = $serviceOptions->pluck('id')->all();
        } elseif ($pieceId && $serviceId) {
            $lines[$lineIndex]['status'] = 'resolved';
            $lines[$lineIndex]['ambiguous_field'] = null;
        }

        $conversation->draft_lines = $lines;

        $messages = $conversation->messages ?? [];
        $optionLabel = collect($question['options'])->firstWhere('id', $optionId)['label'] ?? (string) $optionId;
        $messages[] = ['role' => 'user', 'text' => $optionLabel];
        $conversation->messages = $messages;

        return $this->finalizeDraft($conversation, $locale, null);
    }

    /**
     * @return array{success: bool, status: int, message?: string, data?: array, conflict?: array}
     */
    public function confirm(Client $client, int $conversationId, bool $replaceCart, string $locale): array
    {
        $conversation = SmartOrderConversation::where('client_id', $client->id)->find($conversationId);
        if (! $conversation) {
            return ['success' => false, 'status' => 404, 'message' => __('smart_order.conversation_not_found')];
        }
        if (! $conversation->isReady()) {
            return ['success' => false, 'status' => 422, 'message' => __('smart_order.not_ready_for_confirmation')];
        }

        $items = collect($conversation->draft_lines ?? [])
            ->filter(fn ($line) => ($line['status'] ?? null) === 'resolved')
            ->map(fn ($line) => [
                'piece_id' => $line['piece_id'],
                'service_ids' => [$line['service_id']],
                'additional_service_ids' => [],
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'note' => null,
                'image' => null,
            ])->values()->all();

        if ($replaceCart) {
            $this->cartService->clear($client);
        }

        $result = $this->cartService->addItems($client, (int) $conversation->branch_id, $items, $locale);

        if ($result['success']) {
            $conversation->status = SmartOrderConversation::STATUS_CONFIRMED;
            $conversation->confirmed_at = now();
            $conversation->save();
        }

        return $result;
    }

    /**
     * Resolves laundry selection if still needed, then runs item extraction.
     */
    private function advance(SmartOrderConversation $conversation, string $locale): array
    {
        if (! $conversation->branch_id) {
            $laundryResult = $this->resolveLaundry($conversation, $locale);
            if ($laundryResult !== null) {
                return $laundryResult;
            }
        }

        return $this->extractItems($conversation, $locale);
    }

    /**
     * @return array|null null means laundry was resolved (auto or by name match) — caller continues to item extraction.
     */
    private function resolveLaundry(SmartOrderConversation $conversation, string $locale): ?array
    {
        $client = $conversation->client ?? Client::find($conversation->client_id);
        $address = $client->defaultAddress();

        if (! $address) {
            $conversation->status = SmartOrderConversation::STATUS_NOT_UNDERSTOOD;
            $conversation->last_reply = __('smart_order.address_required');
            $conversation->last_activity_at = now();
            $conversation->save();

            return $this->respond($conversation, 400);
        }

        $branches = $this->availableBranches($client, $address);

        if ($branches->isEmpty()) {
            $conversation->status = SmartOrderConversation::STATUS_NOT_UNDERSTOOD;
            $conversation->last_reply = __('smart_order.no_laundry_available');
            $conversation->last_activity_at = now();
            $conversation->save();

            return $this->respond($conversation, 200);
        }

        $lastMessageText = collect($conversation->messages)->last()['text'] ?? '';

        if ($branches->count() === 1) {
            $this->selectLaundry($conversation, $branches->first(), SmartOrderConversation::SELECTION_AUTO_SINGLE);

            return null;
        }

        $namedMatch = $this->matchLaundryByName($lastMessageText, $branches);
        if ($namedMatch) {
            $this->selectLaundry($conversation, $namedMatch, SmartOrderConversation::SELECTION_USER);

            return null;
        }

        $ranked = $this->rankLaundries($branches, $client, $address);
        $options = $ranked->map(function (Branch $branch) use ($address, $locale) {
            return [
                'id' => $branch->id,
                'label' => $branch->vendor?->getTranslation('name', $locale) ?? $branch->getTranslation('name', $locale),
                'icon' => $this->uploadFilesService->getFullUrl($branch->vendor?->logo),
                'rating' => (float) ($branch->rating ?? 0),
                'distance_km' => ($address->latitude && $address->longitude && $branch->latitude && $branch->longitude)
                    ? round($this->distanceService->distanceKm((float) $address->latitude, (float) $address->longitude, (float) $branch->latitude, (float) $branch->longitude), 2)
                    : null,
            ];
        })->values()->all();

        $conversation->status = SmartOrderConversation::STATUS_NEEDS_CLARIFICATION;
        $conversation->pending_question = [
            'line_key' => null,
            'field' => 'laundry',
            'text' => __('smart_order.ask_laundry'),
            'options' => $options,
        ];
        $conversation->last_reply = __('smart_order.ask_laundry');
        $conversation->last_activity_at = now();
        $conversation->save();

        return $this->respond($conversation, 200);
    }

    private function selectLaundry(SmartOrderConversation $conversation, Branch $branch, string $selectionType): void
    {
        $conversation->vendor_id = $branch->vendor_id;
        $conversation->branch_id = $branch->id;
        $conversation->laundry_selection = $selectionType;
    }

    private function extractItems(SmartOrderConversation $conversation, string $locale): array
    {
        $catalog = $this->buildCatalog((int) $conversation->branch_id);

        $parsed = $this->claudeParser->parse(
            $conversation->messages ?? [],
            $conversation->draft_lines ?? [],
            $catalog,
            $locale
        );

        if ($parsed === null) {
            return ['success' => false, 'status' => 503, 'message' => __('smart_order.something_went_wrong')];
        }

        $lines = [];
        foreach ($parsed['lines'] as $i => $line) {
            if (($line['status'] ?? null) === 'unknown') {
                continue; // dropped silently — Claude's `reply` already explains it per the system prompt
            }
            $line['line_key'] = 'l'.($i + 1);
            $lines[] = $line;
        }

        $conversation->draft_lines = $lines;
        $conversation->last_reply = $parsed['reply'];

        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'assistant', 'text' => $parsed['reply']];
        $conversation->messages = $messages;

        return $this->finalizeDraft($conversation, $locale, (bool) $parsed['not_understood'] && $lines === []);
    }

    /**
     * Shared tail: price resolved lines, compute status, build the next
     * question (one at a time: piece > service), save, respond.
     */
    private function finalizeDraft(SmartOrderConversation $conversation, string $locale, ?bool $notUnderstood): array
    {
        $lines = $conversation->draft_lines ?? [];
        $branchId = (int) $conversation->branch_id;
        $total = 0.0;
        $nextQuestion = null;

        foreach ($lines as $i => &$line) {
            if (($line['status'] ?? null) === 'resolved' && $branchId) {
                $service = Service::find($line['service_id']);
                $unitPrice = $service ? (float) $service->getPriceForPieceAtBranch((int) $line['piece_id'], $branchId) : 0.0;
                $qty = max(1, (int) ($line['quantity'] ?? 1));
                $line['unit_price'] = round($unitPrice, 2);
                $line['line_total'] = round($unitPrice * $qty, 2);
                $total += $line['line_total'];
            }

            if ($nextQuestion === null && ($line['status'] ?? null) === 'ambiguous') {
                $nextQuestion = $this->buildAmbiguousQuestion($line, $branchId, $locale);
            }
        }
        unset($line);

        $conversation->draft_lines = $lines;
        $conversation->estimated_total = round($total, 2);
        $conversation->pending_question = $nextQuestion;

        if ($notUnderstood === true) {
            $conversation->status = SmartOrderConversation::STATUS_NOT_UNDERSTOOD;
        } elseif ($nextQuestion !== null) {
            $conversation->status = SmartOrderConversation::STATUS_NEEDS_CLARIFICATION;
        } else {
            $conversation->status = SmartOrderConversation::STATUS_READY;
        }

        $conversation->last_activity_at = now();
        $conversation->save();

        return $this->respond($conversation, 200);
    }

    private function buildAmbiguousQuestion(array $line, int $branchId, string $locale): array
    {
        if (($line['ambiguous_field'] ?? null) === 'service') {
            $services = Service::whereIn('id', array_slice($line['candidate_service_ids'] ?? [], 0, 4))->get();
            $pieceName = Piece::find($line['piece_id'])?->getTranslation('name', $locale) ?? $line['raw_text'];

            return [
                'line_key' => $line['line_key'],
                'field' => 'service',
                'text' => __('smart_order.ask_service_for_piece', ['piece' => $pieceName]),
                'options' => $services->map(fn ($s) => [
                    'id' => $s->id,
                    'label' => $s->getTranslation('service_name', $locale),
                ])->values()->all(),
            ];
        }

        $pieces = Piece::with('iconRelation')->whereIn('id', array_slice($line['candidate_piece_ids'] ?? [], 0, 4))->get();

        return [
            'line_key' => $line['line_key'],
            'field' => 'piece',
            'text' => $line['raw_text'],
            'options' => $pieces->map(fn ($p) => [
                'id' => $p->id,
                'label' => $p->getTranslation('name', $locale),
                'icon' => $this->uploadFilesService->getFullUrl($p->iconRelation?->full_path ?? $p->iconRelation?->path),
            ])->values()->all(),
        ];
    }

    private function servicesForPiece(int $pieceId, int $branchId): Collection
    {
        $piece = Piece::with(['services' => function ($q) use ($branchId) {
            $q->where('services.is_active', true)->where('service_piece.branch_id', $branchId);
        }])->find($pieceId);

        return $piece ? $piece->services->take(4) : collect();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCatalog(int $branchId): array
    {
        $branch = Branch::find($branchId);
        if (! $branch) {
            return [];
        }

        $pieces = $branch->activePieces()->with(['services' => function ($q) use ($branchId) {
            $q->where('services.is_active', true)->where('service_piece.branch_id', $branchId);
        }])->get();

        return $pieces->map(fn ($piece) => [
            'id' => $piece->id,
            'name_ar' => $piece->getTranslation('name', 'ar'),
            'name_en' => $piece->getTranslation('name', 'en'),
            'services' => $piece->services->map(fn ($s) => [
                'id' => $s->id,
                'name_ar' => $s->getTranslation('service_name', 'ar'),
                'name_en' => $s->getTranslation('service_name', 'en'),
            ])->values()->all(),
        ])->values()->all();
    }

    private function availableBranches(Client $client, $address): Collection
    {
        $query = Branch::where('is_active', true)
            ->whereHas('vendor', function ($q) {
                $q->where('is_active', true)
                    ->where(function ($q2) {
                        $q2->where('is_banned', false)->orWhereNull('is_banned');
                    });
            })
            ->with('vendor');

        if ($address->zone_id) {
            $query->where('zone_id', $address->zone_id);
        }

        return $query->get();
    }

    private function matchLaundryByName(string $text, Collection $branches): ?Branch
    {
        $normalized = mb_strtolower($text);
        $matches = $branches->filter(function (Branch $branch) use ($normalized) {
            $nameAr = mb_strtolower((string) ($branch->vendor?->getTranslation('name', 'ar') ?? ''));
            $nameEn = mb_strtolower((string) ($branch->vendor?->getTranslation('name', 'en') ?? ''));

            return ($nameAr !== '' && str_contains($normalized, $nameAr))
                || ($nameEn !== '' && str_contains($normalized, $nameEn));
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function rankLaundries(Collection $branches, Client $client, $address): Collection
    {
        $criteria = AdminSetting::getValue('smart_order_laundry_ranking', ['last_ordered', 'distance']);
        if (! is_array($criteria) || $criteria === []) {
            $criteria = ['last_ordered', 'distance'];
        }

        $lastOrderedVendorId = null;
        if (in_array('last_ordered', $criteria, true)) {
            $lastOrderedVendorId = Order::where('client_id', $client->id)
                ->whereNotNull('branch_id')
                ->latest()
                ->first()?->branch?->vendor_id;
        }

        $distances = [];
        if (in_array('distance', $criteria, true) && $address->latitude && $address->longitude) {
            $points = $branches->filter(fn (Branch $b) => $b->latitude && $b->longitude)
                ->mapWithKeys(fn (Branch $b) => [$b->id => ['lat' => (float) $b->latitude, 'lon' => (float) $b->longitude]])
                ->all();
            if ($points !== []) {
                $distances = $this->distanceService->distancesTo((float) $address->latitude, (float) $address->longitude, $points);
            }
        }

        return $branches->sort(function (Branch $a, Branch $b) use ($criteria, $lastOrderedVendorId, $distances) {
            foreach ($criteria as $criterion) {
                if ($criterion === 'last_ordered' && $lastOrderedVendorId) {
                    $aMatch = (int) $a->vendor_id === (int) $lastOrderedVendorId;
                    $bMatch = (int) $b->vendor_id === (int) $lastOrderedVendorId;
                    if ($aMatch !== $bMatch) {
                        return $aMatch ? -1 : 1;
                    }
                }
                if ($criterion === 'distance' && $distances !== []) {
                    $da = $distances[$a->id] ?? PHP_FLOAT_MAX;
                    $db = $distances[$b->id] ?? PHP_FLOAT_MAX;
                    if ($da !== $db) {
                        return $da <=> $db;
                    }
                }
                if ($criterion === 'rating') {
                    $ra = (float) ($a->rating ?? 0);
                    $rb = (float) ($b->rating ?? 0);
                    if ($ra !== $rb) {
                        return $rb <=> $ra;
                    }
                }
            }

            return 0;
        })->values();
    }

    private function checkDailyLimit(Client $client): array
    {
        $limit = (int) AdminSetting::getValue('smart_order_daily_message_limit', 30);
        $count = (int) Cache::get($this->dailyCountKey($client), 0);

        return ['allowed' => $count < $limit, 'count' => $count, 'limit' => $limit];
    }

    private function incrementDailyCount(Client $client): void
    {
        $key = $this->dailyCountKey($client);
        $value = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $value, now()->endOfDay());
    }

    private function dailyCountKey(Client $client): string
    {
        return 'smart_order:daily_count:'.$client->id.':'.now()->toDateString();
    }

    private function respond(SmartOrderConversation $conversation, int $status): array
    {
        $data = [
            'conversation_id' => (string) $conversation->id,
            'status' => $conversation->status,
            'reply' => $conversation->last_reply,
            'draft' => [
                'laundry' => $conversation->branch_id ? [
                    'id' => $conversation->vendor_id,
                    'name' => $conversation->vendor?->getTranslation('name', app()->getLocale()),
                    'logo' => $this->uploadFilesService->getFullUrl($conversation->vendor?->logo),
                ] : null,
                'laundry_selection' => $conversation->laundry_selection,
                'lines' => array_map(fn ($line) => [
                    'line_key' => $line['line_key'],
                    'raw_text' => $line['raw_text'],
                    'status' => $line['status'],
                    'piece' => $line['piece_id'] ? $this->pieceSummary((int) $line['piece_id']) : null,
                    'service' => $line['service_id'] ? $this->serviceSummary((int) $line['service_id']) : null,
                    'quantity' => $line['quantity'] ?? 1,
                    'unit_price' => $line['unit_price'] ?? null,
                    'line_total' => $line['line_total'] ?? null,
                ], $conversation->draft_lines ?? []),
                'estimated_total' => $conversation->estimated_total !== null ? (float) $conversation->estimated_total : null,
            ],
            'question' => $conversation->pending_question,
        ];

        return ['success' => true, 'status' => $status, 'data' => $data];
    }

    private function pieceSummary(int $pieceId): ?array
    {
        $piece = Piece::with('iconRelation')->find($pieceId);
        if (! $piece) {
            return null;
        }

        return [
            'id' => $piece->id,
            'name' => $piece->getTranslation('name', app()->getLocale()),
            'icon' => $this->uploadFilesService->getFullUrl($piece->iconRelation?->full_path ?? $piece->iconRelation?->path),
        ];
    }

    private function serviceSummary(int $serviceId): ?array
    {
        $service = Service::find($serviceId);
        if (! $service) {
            return null;
        }

        return [
            'id' => $service->id,
            'name' => $service->getTranslation('service_name', app()->getLocale()),
        ];
    }
}
