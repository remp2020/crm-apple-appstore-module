<?php

namespace Crm\AppleAppstoreModule\Models;

class UnifiedReceipt
{
    protected $unifiedReceipt;

    public function __construct($unifiedReceipt)
    {
        $this->unifiedReceipt = $unifiedReceipt;
    }

    public function getEnvironment(): string
    {
        return $this->unifiedReceipt->environment;
    }

    public function getLatestReceipt(): string
    {
        return $this->unifiedReceipt->latest_receipt;
    }

    /**
     * @return LatestReceiptInfo[]
     */
    public function getLatestReceiptInfo(): array
    {
        /** @var LatestReceiptInfo[] $latestReceiptInfo */
        $latestReceiptInfo = [];
        foreach ($this->unifiedReceipt->latest_receipt_info as $item) {
            $latestReceiptInfo[] = new LatestReceiptInfo($item);
        }
        return $latestReceiptInfo;
    }

    public function getPendingRenewalInfo(): array
    {
        /** @var PendingRenewalInfo[] $pendingRenewalInfo */
        $pendingRenewalInfo = [];
        foreach ($this->unifiedReceipt->pending_renewal_info as $item) {
            $pendingRenewalInfo[] = new PendingRenewalInfo($item);
        }
        return $pendingRenewalInfo;
    }
}
