<?php

namespace Crm\AppleAppstoreModule\Models\ServerToServerNotificationProcessor;

use Nette\Utils\DateTime;

/**
 * Trait ServerToServerNotificationDateTimesTrait serves as default implementation
 * of loading and converting subscription times from Apple's ServerToServerNotification.
 */
trait ServerToServerNotificationDateTimesTrait
{
    public function getSubscriptionStartAt(LatestReceiptInfo $latestReceiptInfo): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $latestReceiptInfo->getPurchaseDateMs()
        );
    }

    public function getSubscriptionEndAt(LatestReceiptInfo $latestReceiptInfo): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $latestReceiptInfo->getExpiresDateMs()
        );
    }

    public function getOriginalPurchaseDate(LatestReceiptInfo $latestReceiptInfo): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $latestReceiptInfo->getOriginalPurchaseDateMs()
        );
    }

    public function getCancellationDate(LatestReceiptInfo $latestReceiptInfo): ?DateTime
    {
        if ($latestReceiptInfo->getCancellationDateMs() === null) {
            return null;
        }

        return $this->convertTimestampWithMilliseconds(
            $latestReceiptInfo->getCancellationDateMs()
        );
    }

    public function getGracePeriodEndDate(PendingRenewalInfo $pendingRenewalInfo): ?DateTime
    {
        if ($pendingRenewalInfo->getGracePeriodExpiresDateMs() === null) {
            return null;
        }

        return $this->convertTimestampWithMilliseconds(
            $pendingRenewalInfo->getGracePeriodExpiresDateMs()
        );
    }

    /**
     * Converts $timestampWithMillisecond to \Nette\Utils\DateTime with default system timezone.
     */
    private function convertTimestampWithMilliseconds(string $timestampWithMilliseconds): DateTime
    {
        // we need to convert 1136214245000 to 1136214245.000000 (not *.000); otherwise createFromFormat fails
        $convertedTimestamp = number_format((((int) $timestampWithMilliseconds) / 1000), 6, '.', '');
        $returnDateTime = DateTime::createFromFormat("U.u", $convertedTimestamp);

        $returnDateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $returnDateTime;
    }
}
