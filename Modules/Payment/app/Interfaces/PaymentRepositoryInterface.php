<?php

namespace Modules\Payment\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Payment\Models\Payment;

interface PaymentRepositoryInterface
{
    public function all(array $filters = []): LengthAwarePaginator;

    public function find(int $id): ?Payment;

    public function create(array $data): Payment;

    public function update(Payment $payment, array $data): bool;

    public function delete(Payment $payment): bool;
}
