<?php

namespace Crm\AppleAppstoreModule\Models;

use Nette\Utils\DateTime;
use Readdle\AppStoreServerAPI\RenewalInfo;
use Readdle\AppStoreServerAPI\TransactionInfo;

trait AppStoreServerDateTimesTrait
{
    public function getSubscriptionStartAt(TransactionInfo $transactionInfo): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $transactionInfo->getPurchaseDate(),
        );
    }

    public function getSubscriptionEndAt(TransactionInfo $transactionInfo): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $transactionInfo->getExpiresDate(),
        );
    }

    public function getOriginalPurchaseDate(TransactionInfo $transactionInfo): DateTime
    {
        return $this->convertTimestampWithMilliseconds(
            $transactionInfo->getOriginalPurchaseDate(),
        );
    }

    public function getCancellationDate(TransactionInfo $transactionInfo): ?DateTime
    {
        if ($transactionInfo->getRevocationDate() === null) {
            return null;
        }

        return $this->convertTimestampWithMilliseconds(
            $transactionInfo->getRevocationDate(),
        );
    }

    public function getGracePeriodEndDate(RenewalInfo $renewalInfo): ?DateTime
    {
        if ($renewalInfo->getGracePeriodExpiresDate() === null) {
            return null;
        }

        return $this->convertTimestampWithMilliseconds(
            $renewalInfo->getGracePeriodExpiresDate(),
        );
    }

    public function getRenewalDate(RenewalInfo $renewalInfo): ?DateTime
    {
        if ($renewalInfo->getRenewalDate() === null) {
            return null;
        }

        return $this->convertTimestampWithMilliseconds(
            $renewalInfo->getRenewalDate(),
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
