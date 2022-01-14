<?php

namespace Crm\AppleAppstoreModule\Model;

class LatestReceiptInfo
{
    protected $latestReceiptInfo;

    public function __construct($latestReceiptInfo)
    {
        $this->latestReceiptInfo = $latestReceiptInfo;
    }

    public function getCancellationDateMs(): ?string
    {
        return $this->latestReceiptInfo->cancellation_date_ms ?? null;
    }

    public function getCancellationReason(): ?string
    {
        return $this->latestReceiptInfo->cancellation_reason ?? null;
    }

    public function getExpiresDateMs(): string
    {
        return $this->latestReceiptInfo->expires_date_ms;
    }

    public function getOriginalPurchaseDateMs(): string
    {
        return $this->latestReceiptInfo->original_purchase_date_ms;
    }

    public function getOriginalTransactionId(): string
    {
        return $this->latestReceiptInfo->original_transaction_id;
    }

    public function getProductId(): string
    {
        return $this->latestReceiptInfo->product_id;
    }

    public function getPurchaseDateMs(): string
    {
        return $this->latestReceiptInfo->purchase_date_ms;
    }

    public function getQuantity(): int
    {
        return (int) $this->latestReceiptInfo->quantity;
    }

    public function getTransactionId(): string
    {
        return $this->latestReceiptInfo->transaction_id;
    }

    public function isTrialPeriod(): bool
    {
        if (!isset($this->latestReceiptInfo->is_trial_period)) {
            return false;
        }
        return filter_var($this->latestReceiptInfo->is_trial_period, FILTER_VALIDATE_BOOLEAN);
    }
}
