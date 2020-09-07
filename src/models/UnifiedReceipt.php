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
}
