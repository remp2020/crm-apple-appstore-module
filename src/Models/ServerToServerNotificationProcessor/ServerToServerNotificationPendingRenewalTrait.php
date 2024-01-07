<?php

namespace Crm\AppleAppstoreModule\Models\ServerToServerNotificationProcessor;

use Crm\AppleAppstoreModule\Models\PendingRenewalInfo;
use Crm\AppleAppstoreModule\Models\ServerToServerNotification;

/**
 * Trait ServerToServerNotificationLatestReceiptTrait serves as default implementation
 * of loading latest transaction from latest receipt info from Apple's ServerToServerNotification.
 */
trait ServerToServerNotificationPendingRenewalTrait
{
    public function getLatestPendingRenewalInfo(ServerToServerNotification $serverToServerNotification): PendingRenewalInfo
    {
        // get expire date of each original transaction ID, we'll need that to sort pending renewal info
        $latestReceiptInfoArray = $serverToServerNotification->getUnifiedReceipt()->getLatestReceiptInfo();
        $transactionExpiresDateMs = [];
        foreach ($latestReceiptInfoArray as $latestReceiptInfo) {
            $ed = $latestReceiptInfo->getExpiresDateMs();
            $otID = $latestReceiptInfo->getOriginalTransactionId();

            if (!isset($transactionExpiresDateMs[$otID]) || $transactionExpiresDateMs[$otID] < $ed) {
                $transactionExpiresDateMs[$otID] = $ed;
            }
        }

        // Sort the pending renewal info based on expire_date_ms, because apple is not able to send us only one element.
        // Sorting is based on the notes at https://developer.apple.com/forums/thread/658410.
        $pendingRenewalInfoArray = $serverToServerNotification->getUnifiedReceipt()->getPendingRenewalInfo();
        usort(
            $pendingRenewalInfoArray,
            static function (PendingRenewalInfo $a, PendingRenewalInfo $b) use ($transactionExpiresDateMs) {
                $aExpire = $transactionExpiresDateMs[$a->getOriginalTransactionId()];
                $bExpire = $transactionExpiresDateMs[$b->getOriginalTransactionId()];

                if ($aExpire === $bExpire) {
                    return 0;
                }
                return $aExpire > $bExpire ? -1 : 1;
            }
        );

        return reset($pendingRenewalInfoArray);
    }
}
