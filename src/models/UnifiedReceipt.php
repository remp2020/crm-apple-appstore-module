<?php

namespace Crm\AppleAppstoreModule\Model;

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

    public function getLatestReceiptInfo(): LatestReceiptInfo
    {
        return new LatestReceiptInfo($this->unifiedReceipt->latest_receipt_info);
    }
}
