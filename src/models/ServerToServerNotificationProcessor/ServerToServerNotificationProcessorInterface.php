<?php

namespace Crm\AppleAppstoreModule\Model;

use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

interface ServerToServerNotificationProcessorInterface
{
    /**
     * getLatestLatestReceiptInfo returns latest transaction (single latest receipt info)
     * from Apple's ServerToServerNotification.
     *
     * We recommend using ServerToServerNotificationLatestReceiptTrait which loads
     * all latest receipts info from ServerToServerNotification and finds latest transaction.
     */
    public function getLatestLatestReceiptInfo(ServerToServerNotification $serverToServerNotification): LatestReceiptInfo;

    /**
     * getSubscriptionStartAt returns subscription's start DateTime from Apple's LatestReceiptInfo.
     *
     * We recommend using ServerToServerNotificationDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getSubscriptionStartAt(LatestReceiptInfo $latestReceiptInfo): DateTime;

    /**
     * getSubscriptionEndAt returns subscription's end DateTime from Apple's LatestReceiptInfo.
     *
     * We recommend using ServerToServerNotificationDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getSubscriptionEndAt(LatestReceiptInfo $latestReceiptInfo): DateTime;

    /**
     * getOriginalPurchaseDate returns DateTime of original purchase by user from Apple's LatestReceiptInfo.
     *
     * We recommend using ServerToServerNotificationDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getOriginalPurchaseDate(LatestReceiptInfo $latestReceiptInfo): DateTime;

    /**
     * getCancellationDate returns DateTime of cancellation by user from Apple's LatestReceiptInfo.
     *
     * We recommend using ServerToServerNotificationDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getCancellationDate(LatestReceiptInfo $latestReceiptInfo): ?DateTime;

    /**
     * getSubscriptionType returns SubscriptionType from Apple's LatestReceiptInfo.
     *
     * @throws \Exception
     */
    public function getSubscriptionType(LatestReceiptInfo $latestReceiptInfo): ActiveRow;

    /**
     * getUser returns User from Apple's LatestReceiptInfo.
     *
     * @throws \Exception
     * @throws DoNotRetryException - Thrown in case processing should be stopped but processor wants to stop retries.
     */
    public function getUser(LatestReceiptInfo $latestReceiptInfo): ActiveRow;
}
