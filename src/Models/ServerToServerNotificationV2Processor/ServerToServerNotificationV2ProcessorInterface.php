<?php

namespace Crm\AppleAppstoreModule\Models\ServerToServerNotificationV2Processor;

use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Readdle\AppStoreServerAPI\RenewalInfo;
use Readdle\AppStoreServerAPI\TransactionInfo;

interface ServerToServerNotificationV2ProcessorInterface
{
    /**
     * getSubscriptionStartAt returns subscription's start DateTime from Apple's TransactionInfo.
     *
     * We recommend using AppStoreServerDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getSubscriptionStartAt(TransactionInfo $transactionInfo): DateTime;

    /**
     * getSubscriptionEndAt returns subscription's end DateTime from Apple's TransactionInfo.
     *
     * We recommend using AppStoreServerDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getSubscriptionEndAt(TransactionInfo $transactionInfo): DateTime;

    /**
     * getOriginalPurchaseDate returns DateTime of original purchase by user from Apple's TransactionInfo.
     *
     * We recommend using AppStoreServerDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getOriginalPurchaseDate(TransactionInfo $transactionInfo): DateTime;

    /**
     * getCancellationDate returns DateTime of cancellation by user from Apple's TransactionInfo.
     *
     * We recommend using AppStoreServerDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getCancellationDate(TransactionInfo $transactionInfo): ?DateTime;

    /**
     * getGracePeriodEndDate returns DateTime of end of grace period from Apple's RenewalInfo.
     *
     * The grace period is configurable and covers billing issues on the Apple side. Client should create full-access
     * free subscription ending at the provided date.
     *
     * We recommend using AppStoreServerDateTimesTrait which converts Apple's timestamp
     * with milliseconds to DateTime with system Timezone.
     */
    public function getGracePeriodEndDate(RenewalInfo $renewalInfo): ?DateTime;

    /**
     * getSubscriptionType returns SubscriptionType from Apple's TransactionInfo.
     *
     * @throws \Exception
     */
    public function getSubscriptionType(TransactionInfo $transactionInfo): ActiveRow;

    /**
     * getUser returns User from Apple's TransactionInfo.
     *
     * @throws \Exception
     */
    public function getUser(TransactionInfo $transactionInfo): ActiveRow;
}
