<?php

namespace Modules\Payment\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Payment\Contracts\PaymentGatewayInterface;
use Modules\Payment\DTOs\PaymentRequest;
use Modules\Payment\DTOs\PaymentResponse;
use Modules\Payment\DTOs\RefundRequest;
use Modules\Payment\DTOs\RefundResponse;
use Modules\Payment\Interfaces\PaymentRepositoryInterface;
use Modules\Payment\Models\Payment;

class PaymentService
{
    private ?PaymentRepositoryInterface $paymentRepository = null;

    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    private ?PaymentGatewayInterface $activeGateway = null;

    public function __construct(?PaymentRepositoryInterface $paymentRepository = null)
    {
        $this->paymentRepository = $paymentRepository;
    }

    // Repository-based methods (optional)
    public function getAllPayments(array $filters = []): ?LengthAwarePaginator
    {
        if (! $this->paymentRepository) {
            return null;
        }

        return $this->paymentRepository->all($filters);
    }

    public function getPaymentById(int $id): ?Payment
    {
        if (! $this->paymentRepository) {
            return null;
        }

        return $this->paymentRepository->find($id);
    }

    public function createPayment(array $data): ?Payment
    {
        if (! $this->paymentRepository) {
            return null;
        }

        return $this->paymentRepository->create($data);
    }

    public function updatePayment(int $id, array $data): ?Payment
    {
        if (! $this->paymentRepository) {
            return null;
        }
        $payment = $this->paymentRepository->find($id);

        if (! $payment) {
            return null;
        }

        $this->paymentRepository->update($payment, $data);

        return $payment->fresh();
    }

    public function deletePayment(int $id): bool
    {
        if (! $this->paymentRepository) {
            return false;
        }
        $payment = $this->paymentRepository->find($id);

        if (! $payment) {
            return false;
        }

        return $this->paymentRepository->delete($payment);
    }

    // Gateway management and delegation
    public function registerGateway(string $name, PaymentGatewayInterface $gateway): void
    {
        $this->gateways[$name] = $gateway;
    }

    public function setGateway(string $name): void
    {
        $key = $name;
        if (! isset($this->gateways[$key])) {
            // try normalized keys
            $lower = strtolower($name);
            foreach ($this->gateways as $k => $g) {
                if (strtolower($k) === $lower) {
                    $key = $k;
                    break;
                }
            }
        }

        if (! isset($this->gateways[$key])) {
            throw new \Exception("Payment gateway '{$name}' is not registered");
        }

        $this->activeGateway = $this->gateways[$key];
    }

    public function getActiveGateway(): ?PaymentGatewayInterface
    {
        return $this->activeGateway;
    }

    /**
     * Get a specific gateway by name without setting it as active
     */
    public function getGateway(string $name): ?PaymentGatewayInterface
    {
        $key = $name;
        if (! isset($this->gateways[$key])) {
            // try normalized keys
            $lower = strtolower($name);
            foreach ($this->gateways as $k => $g) {
                if (strtolower($k) === $lower) {
                    return $g;
                }
            }
        }

        return $this->gateways[$key] ?? null;
    }

    /** @return array<string, PaymentGatewayInterface> */
    public function getEnabledGateways(): array
    {
        return $this->gateways;
    }

    public function initializePayment(PaymentRequest $request): PaymentResponse
    {
        if (! $this->activeGateway) {
            throw new \Exception('No active payment gateway set');
        }

        return $this->activeGateway->initializePayment($request);
    }

    public function verifyPayment(string $transactionId): PaymentResponse
    {
        if (! $this->activeGateway) {
            throw new \Exception('No active payment gateway set');
        }

        return $this->activeGateway->verifyPayment($transactionId);
    }

    public function capture(string $fortId, float $amount, string $merchantReference, ?string $paymentOption = null): PaymentResponse
    {
        if (! $this->activeGateway) {
            throw new \Exception('No active payment gateway set');
        }

        return $this->activeGateway->capture($fortId, $amount, $merchantReference, $paymentOption);
    }

    public function voidAuthorization(string $fortId, string $merchantReference, ?string $paymentOption = null): PaymentResponse
    {
        if (! $this->activeGateway) {
            throw new \Exception('No active payment gateway set');
        }

        return $this->activeGateway->voidAuthorization($fortId, $merchantReference, $paymentOption);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        if (! $this->activeGateway) {
            throw new \Exception('No active payment gateway set');
        }

        return $this->activeGateway->refund($request);
    }

    public function getPaymentStatus(string $transactionId): string
    {
        if (! $this->activeGateway) {
            return 'unknown';
        }

        return $this->activeGateway->getPaymentStatus($transactionId);
    }
}
