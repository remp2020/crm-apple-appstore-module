<?php

namespace Crm\AppleAppstoreModule\Model;

use Nette\Utils\DateTime;

/**
 * Trait ServerToServerNotificationDateTimesTrait serves as default implementation
 * of loading and converting subscription times from Apple's ServerToServerNotification.
 */
trait ServerToServerNotificationDateTimesTrait
{
    public function getSubscriptionStartAt(ServerToServerNotification $serverToServerNotification): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $serverToServerNotification
                ->getUnifiedReceipt()
                ->getLatestReceiptInfo()
                ->getPurchaseDateMs()
        );
    }

    public function getSubscriptionEndAt(ServerToServerNotification $serverToServerNotification): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $serverToServerNotification
                ->getUnifiedReceipt()
                ->getLatestReceiptInfo()
                ->getExpiresDateMs()
        );
    }

    public function getOriginalPurchaseDate(ServerToServerNotification $serverToServerNotification): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $serverToServerNotification
                ->getUnifiedReceipt()
                ->getLatestReceiptInfo()
                ->getOriginalPurchaseDateMs()
        );
    }

    public function getCancellationDate(ServerToServerNotification $serverToServerNotification): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $serverToServerNotification
                ->getUnifiedReceipt()
                ->getLatestReceiptInfo()
                ->getCancellationDateMs()
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
