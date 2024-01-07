<?php

namespace Crm\AppleAppstoreModule\Models\ServerToServerNotificationProcessor;

/**
 * Trait ServerToServerNotificationLatestReceiptTrait serves as default implementation
 * of loading latest transaction from latest receipt info from Apple's ServerToServerNotification.
 */
trait ServerToServerNotificationLatestReceiptTrait
{
    public function getLatestLatestReceiptInfo(ServerToServerNotification $serverToServerNotification): LatestReceiptInfo
    {
        $latestReceiptInfoArray = $serverToServerNotification->getUnifiedReceipt()->getLatestReceiptInfo();
        /** @var LatestReceiptInfo $latestReceiptInfo */
        $latestReceiptInfo = reset($latestReceiptInfoArray);
        foreach ($latestReceiptInfoArray as $latestReceiptInfoItem) {
            // get "latest" latest receipt info; check cancellation date first
            if (($latestReceiptInfo->getCancellationDateMs() ?? $latestReceiptInfo->getPurchaseDateMs())
                < ($latestReceiptInfoItem->getCancellationDateMs() ?? $latestReceiptInfoItem->getPurchaseDateMs())) {
                $latestReceiptInfo = $latestReceiptInfoItem;
            }
        }
        return $latestReceiptInfo;
    }
}
