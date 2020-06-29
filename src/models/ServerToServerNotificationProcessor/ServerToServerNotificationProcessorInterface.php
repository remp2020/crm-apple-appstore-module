<?php

namespace Crm\AppleAppstoreModule\Model;

use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

interface ServerToServerNotificationProcessorInterface
{
    /**
     * getSubscriptionStartAt returns subscription's start DateTime from Apple's ServerToServerNotification.
     *
     * We recommend using ServerToServerNotificationDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getSubscriptionStartAt(ServerToServerNotification $serverToServerNotification): DateTime;

    /**
     * getSubscriptionEndAt returns subscription's end DateTime from Apple's ServerToServerNotification.
     *
     * We recommend using ServerToServerNotificationDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getSubscriptionEndAt(ServerToServerNotification $serverToServerNotification): DateTime;

    /**
     * getOriginalPurchaseDate returns DateTime of original purchase by user from Apple's ServerToServerNotification.
     *
     * We recommend using ServerToServerNotificationDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getOriginalPurchaseDate(ServerToServerNotification $serverToServerNotification): DateTime;

    /**
     * getCancellationDate returns DateTime of cancellation by user from Apple's ServerToServerNotification.
     *
     * We recommend using ServerToServerNotificationDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getCancellationDate(ServerToServerNotification $serverToServerNotification): DateTime;

    /**
     * getSubscriptionType returns SubscriptionType from Apple's ServerToServerNotification.
     *
     * throws \Exception
     */
    public function getSubscriptionType(ServerToServerNotification $serverToServerNotification): ActiveRow;

    /**
     * getUser returns User from Apple's ServerToServerNotification.
     *
     * throws \Exception
     */
    public function getUser(ServerToServerNotification $serverToServerNotification): ActiveRow;
}
