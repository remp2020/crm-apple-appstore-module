<?php

namespace Crm\AppleAppstoreModule\Models\ServerToServerNotificationProcessor;

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
     * getLatestPendingRenewalInfo returns the latest pending renewal info (single latest record) from Apple's
     * ServerToServer notification.
     */
    public function getLatestPendingRenewalInfo(ServerToServerNotification $serverToServerNotification): PendingRenewalInfo;

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
     * getGracePeriodEndDate returns DateTime of end of grace period from Apple's PendingRenewalInfo.
     *
     * The grace period is configurable and covers billing issues on the Apple side. Client should create full-access
     * free subscription ending at the provided date.
     *
     * We recommend using ServerToServerNotificationDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getGracePeriodEndDate(PendingRenewalInfo $pendingRenewalInfo): ?DateTime;

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
