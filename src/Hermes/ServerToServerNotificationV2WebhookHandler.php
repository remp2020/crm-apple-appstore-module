<?php

namespace Crm\AppleAppstoreModule\Hermes;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Models\AppStoreServerDateTimesTrait;
use Crm\AppleAppstoreModule\Models\Config;
use Crm\AppleAppstoreModule\Models\ServerToServerNotificationV2Processor\ServerToServerNotificationV2ProcessorInterface;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreOriginalTransactionsRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreServerToServerNotificationLogRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Models\NowTrait;
use Crm\ApplicationModule\Models\Redis\RedisClientFactory;
use Crm\ApplicationModule\Models\Redis\RedisClientTrait;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
use Crm\PaymentsModule\Models\RecurrentPaymentsProcessor;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Malkusch\Lock\Mutex\RedisMutex;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;
use Readdle\AppStoreServerAPI\RenewalInfo;
use Readdle\AppStoreServerAPI\ResponseBodyV2;
use Readdle\AppStoreServerAPI\TransactionInfo;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;
use Tracy\Debugger;
use Tracy\ILogger;

class ServerToServerNotificationV2WebhookHandler implements HandlerInterface
{
    use RedisClientTrait;
    use RetryTrait;
    use NowTrait;
    use AppStoreServerDateTimesTrait;

    public const INFO_LOG_LEVEL = 'apple_s2s_notifications';

    public function __construct(
        private readonly ServerToServerNotificationV2ProcessorInterface $serverToServerNotificationV2Processor,
        private readonly PaymentGatewaysRepository $paymentGatewaysRepository,
        private readonly PaymentMetaRepository $paymentMetaRepository,
        private readonly PaymentsRepository $paymentsRepository,
        private readonly RecurrentPaymentsRepository $recurrentPaymentsRepository,
        private readonly SubscriptionsRepository $subscriptionsRepository,
        private readonly AppleAppstoreServerToServerNotificationLogRepository $serverToServerNotificationLogRepository,
        private readonly AppleAppstoreOriginalTransactionsRepository $appleAppstoreOriginalTransactionsRepository,
        private readonly RecurrentPaymentsProcessor $recurrentPaymentsProcessor,
        private readonly AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        private readonly UsersRepository $usersRepository,
        private readonly UserMetaRepository $userMetaRepository,
        private readonly UnclaimedUser $unclaimedUser,
        private readonly ApplicationConfig $applicationConfig,
        RedisClientFactory $redisClientFactory,
    ) {
        $this->redisClientFactory = $redisClientFactory;
    }

    public function handle(MessageInterface $message): bool
    {
        $payload = $message->getPayload();
        return $this->process($payload['notification']);
    }

    private function process(string $notification): bool
    {
        $responseBodyV2 = ResponseBodyV2::createFromRawNotification(
            $notification,
            $this->applicationConfig->get(Config::NOTIFICATION_CERTIFICATE),
        );

        $notificationType = $responseBodyV2->getNotificationType();
        $subType = $responseBodyV2->getSubtype();
        $transactionInfo = $responseBodyV2->getAppMetadata()->getTransactionInfo();

        if (!$transactionInfo) {
            return false;
        }

        $renewalInfo = $responseBodyV2->getAppMetadata()->getRenewalInfo();
        $originalTransactionId = $transactionInfo->getOriginalTransactionId();
        $transactionId = $transactionInfo->getTransactionId();

        // log notification
        $stsNotificationLog = $this->serverToServerNotificationLogRepository->add(
            Json::encode($responseBodyV2),
            $originalTransactionId,
        );
        // upsert original transaction
        $this->appleAppstoreOriginalTransactionsRepository->add($originalTransactionId);

        $mutex = new RedisMutex(
            $this->redis(),
            'process_apple_transaction_id_' . $transactionId,
            60,
        );

        // Mutex to avoid app and S2S notification procession collision (and therefore e.g. multiple payments to be created)
        $payment = $mutex->synchronized(function () use ($transactionInfo, $notificationType, $subType, $renewalInfo, $stsNotificationLog) {
            switch ($notificationType) {
                case ResponseBodyV2::NOTIFICATION_TYPE__SUBSCRIBED:
                    return $this->createPayment($transactionInfo);

                case ResponseBodyV2::NOTIFICATION_TYPE__DID_RENEW:
                    return $this->createRenewedPayment($transactionInfo);

                case ResponseBodyV2::NOTIFICATION_TYPE__DID_CHANGE_RENEWAL_PREF:
                    switch ($subType) {
                        case (ResponseBodyV2::SUBTYPE__DOWNGRADE):
                            return $this->downgrade($transactionInfo, $renewalInfo);

                        case (ResponseBodyV2::SUBTYPE__UPGRADE):
                            return $this->upgrade($transactionInfo);

                        case (null):
                            return $this->revertRenewalChange($transactionInfo);
                    }
                    break;

                case ResponseBodyV2::NOTIFICATION_TYPE__DID_CHANGE_RENEWAL_STATUS:
                    return $this->changeRenewalStatus($transactionInfo, $subType);

                case ResponseBodyV2::NOTIFICATION_TYPE__EXPIRED:
                    return $this->handleExpired($transactionInfo);

                case ResponseBodyV2::NOTIFICATION_TYPE__DID_FAIL_TO_RENEW:
                    return $this->handleFailedRenewal($renewalInfo, $subType);

                case ResponseBodyV2::NOTIFICATION_TYPE__CONSUMPTION_REQUEST:
                    // Do nothing. We may or may not respond to this request by calling Consumption API, but only
                    // if we obtained user's consent, and we should do that within 12 hours of this request.
                    break;

                case ResponseBodyV2::NOTIFICATION_TYPE__REFUND_DECLINED:
                    // Do nothing. We don't initiate refund requests, and we don't need to store this information.
                    break;

                case ResponseBodyV2::NOTIFICATION_TYPE__REFUND:
                    return $this->handleRefund($transactionInfo);

                case ResponseBodyV2::NOTIFICATION_TYPE__REFUND_REVERSED:
                    return $this->handleRefundReversed($transactionInfo);

                default:
                    $this->serverToServerNotificationLogRepository->changeStatus(
                        $stsNotificationLog,
                        AppleAppstoreServerToServerNotificationLogRepository::STATUS_ERROR,
                    );
                    $errorMessage = "Unknown `notification_type` [{$notificationType}].";
                    Debugger::log($errorMessage, Debugger::ERROR);
            }
            return null;
        });

        if (!$payment) {
            // unknown status, report "error" in processing to hermes
            return false;
        }

        $this->serverToServerNotificationLogRepository->addPayment(
            $stsNotificationLog,
            $payment,
        );
        return true;
    }

    /**
     * Create payment from Apple's AppStore ServerToServerNotification
     *
     * @return ActiveRow|null Payment
     * @throws \Exception Thrown when quantity is different than '1'. Only one subscription per purchase is allowed.
     */
    private function createPayment(TransactionInfo $transactionInfo, bool $isUpgrade = false): ?ActiveRow
    {
        $payment = $this->findPaymentByTransactionId($transactionInfo->getTransactionId());
        if ($payment) {
            return $payment;
        }

        $this->checkQuantity($transactionInfo);

        $subscriptionStartDate = $this->getSubscriptionStartAt($transactionInfo);
        $subscriptionEndDate = $this->getSubscriptionEndAt($transactionInfo);
        if ($subscriptionEndDate < $this->getNow()) {
            return null;
        }

        $subscriptionType = $this->serverToServerNotificationV2Processor->getSubscriptionType($transactionInfo);
        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));
        $paymentGateway = $this->getPaymentGatewayByCode(AppleAppstoreGateway::GATEWAY_CODE);

        $payment = $this->paymentsRepository->add(
            subscriptionType: $subscriptionType,
            paymentGateway: $paymentGateway,
            user: $this->serverToServerNotificationV2Processor->getUser($transactionInfo),
            paymentItemContainer: $paymentItemContainer,
            amount: $subscriptionType->price,
            subscriptionStartAt: $subscriptionStartDate,
            subscriptionEndAt: $subscriptionEndDate,
            metaData: $this->preparePaymentMetas($transactionInfo),
        );
        $this->paymentsRepository->update($payment, [
            'paid_at' => $subscriptionStartDate,
        ]);
        $payment = $this->paymentsRepository->updateStatus($payment, PaymentStatusEnum::Prepaid->value);

        if (!$isUpgrade) {
            // handle recurrent payment
            // - original_transaction_id will be used as recurrent token
            // - stop any previous recurrent payments with the same original transaction id
            $activeOriginalTransactionRecurrents = $this->recurrentPaymentsRepository
                ->getUserActiveRecurrentPayments($payment->user_id)
                ->where(['payment_method.external_token' => $transactionInfo->getOriginalTransactionId()])
                ->fetchAll();
            foreach ($activeOriginalTransactionRecurrents as $rp) {
                $this->recurrentPaymentsRepository->stoppedBySystem($rp->id);
            }
        }

        $this->recurrentPaymentsRepository->createFromPayment(
            $payment,
            $transactionInfo->getOriginalTransactionId(),
        );
        // reload payment with its relationships
        return $this->paymentsRepository->find($payment->id);
    }

    /**
     * @throws \Exception Thrown by ServerToServerNotificationProcessor when processing failed and it shouldn't be retried.
     */
    private function createRenewedPayment(TransactionInfo $transactionInfo): ActiveRow
    {
        $payment = $this->findPaymentByTransactionId($transactionInfo->getTransactionId());
        if ($payment) {
            return $payment;
        }

        $this->checkQuantity($transactionInfo);

        $subscriptionStartAt = $this->getSubscriptionStartAt($transactionInfo);
        $subscriptionEndAt = $this->getSubscriptionEndAt($transactionInfo);
        $subscriptionType = $this->serverToServerNotificationV2Processor->getSubscriptionType($transactionInfo);

        $lastPayment = $this->findLastPaymentByOriginalTransactionId($transactionInfo->getOriginalTransactionId(), $subscriptionType);
        if (!isset($lastPayment)) {
            Debugger::log("Unable to find previous payment for renewal with same `original_transaction_id` [{$transactionInfo->getOriginalTransactionId()}].", Debugger::ERROR);
        } else {
            if ($lastPayment->subscription_end_at > $subscriptionStartAt) {
                throw new \Exception("Purchased payment starts [{$subscriptionStartAt}] before previous subscription ends [{$lastPayment->subscription_end_at}].");
            }
        }

        $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);

        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));
        $paymentGateway = $this->getPaymentGatewayByCode(AppleAppstoreGateway::GATEWAY_CODE);

        $payment = $this->paymentsRepository->add(
            subscriptionType: $subscriptionType,
            paymentGateway: $paymentGateway,
            user: $this->serverToServerNotificationV2Processor->getUser($transactionInfo),
            paymentItemContainer: $paymentItemContainer,
            amount: $subscriptionType->price,
            subscriptionStartAt: $subscriptionStartAt,
            subscriptionEndAt: $subscriptionEndAt,
            recurrentCharge: true,
            metaData: $this->preparePaymentMetas($transactionInfo),
        );
        $this->paymentsRepository->update($payment, [
            'paid_at' => $subscriptionStartAt,
        ]);

        if ($lastRecurrentPayment) {
            $this->recurrentPaymentsRepository->update($lastRecurrentPayment, [
                'payment_id' => $payment->id,
            ]);
            $this->recurrentPaymentsProcessor->processChargedRecurrent(
                $lastRecurrentPayment,
                PaymentStatusEnum::Prepaid->value,
                0,
                'NOTIFICATION',
            );
        } else {
            $payment = $this->paymentsRepository->updateStatus(
                payment: $payment,
                status: PaymentStatusEnum::Prepaid->value,
                sendEmail: true,
            );

            // create recurrent payment; original_transaction_id will be used as recurrent token
            $this->recurrentPaymentsRepository->createFromPayment(
                $payment,
                $transactionInfo->getOriginalTransactionId(),
            );
        }

        return $payment;
    }

    private function downgrade(TransactionInfo $transactionInfo, RenewalInfo $renewalInfo)
    {
        $payment = $this->findPaymentByTransactionId($transactionInfo->getTransactionId());
        if (!$payment) {
            throw new \Exception("Unable to find payment with 'transaction_id' [{$transactionInfo->getTransactionId()}].");
        }

        $recurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        if (!isset($recurrent)) {
            throw new \Exception("Unable to downgrade subscription. No recurrent payment for parent payment ID [{$payment->id}] found.");
        }

        $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository->findSubscriptionTypeByAppleAppstoreProductId($renewalInfo->getAutoRenewProductId());
        if (!isset($subscriptionType)) {
            throw new \Exception("Unable to downgrade subscription. No subscription type with `autoRenewProductId` [{$renewalInfo->getAutoRenewProductId()}] found.");
        }

        $this->recurrentPaymentsRepository->update($recurrent, [
            'next_subscription_type_id' => $subscriptionType->id,
        ]);

        return $recurrent->parent_payment;
    }

    private function upgrade(TransactionInfo $transactionInfo)
    {
        $lastBaseRecurrentSelection = $this->recurrentPaymentsRepository->getTable()->where([
            'payment_method.external_token' => $transactionInfo->getOriginalTransactionId(),
        ])->order('charge_at DESC');

        // handle upgrade notification after verify purchase API call
        $upgradedPayment = $this->findPaymentByTransactionId($transactionInfo->getTransactionId());
        if ($upgradedPayment) {
            $lastBaseRecurrentSelection->where([
                'parent_payment_id != ?' => $upgradedPayment->id,
            ]);
        }

        $lastBaseRecurrent = $lastBaseRecurrentSelection->fetch();
        if (!$lastBaseRecurrent) {
            throw new \Exception("Unable to find base recurrent payment for upgrade with OriginalTransactionId (CID) " .
                "[{$transactionInfo->getOriginalTransactionId()}]");
        }

        $upgradedPayment = $upgradedPayment ?? $this->createPayment($transactionInfo, true);
        $upgradedRecurrent = $this->recurrentPaymentsRepository->recurrent($upgradedPayment);

        $payment = $lastBaseRecurrent->parent_payment;
        $subscription = $payment->subscription;
        if (!$subscription) {
            throw new \Exception("No subscription related to payment with ID: [{$payment->id}].");
        }

        $this->paymentsRepository->update($payment, [
            'note' => "Upgrade to payment ID: [{$upgradedPayment->id}]",
        ]);

        $this->recurrentPaymentsRepository->update($lastBaseRecurrent, [
            'state' => RecurrentPaymentStateEnum::Charged->value,
            'payment_id' => $upgradedPayment->id,
            'next_subscription_type_id' => $upgradedPayment->subscription_type_id,
            'note' => "Upgrade to recurrent payment ID: [{$upgradedRecurrent->id}]",
            'status' => 'OK',
            'approval' => 'OK',
        ]);

        $this->subscriptionsRepository->update($subscription, [
            'end_time' => $this->getSubscriptionStartAt($transactionInfo),
            'note' => "Upgrade",
        ]);

        return $payment;
    }

    private function revertRenewalChange(TransactionInfo $transactionInfo)
    {
        $payment = $this->findPaymentByTransactionId($transactionInfo->getTransactionId());
        if (!$payment) {
            throw new \Exception("Unable to find payment with 'transaction_id' [{$transactionInfo->getTransactionId()}].");
        }

        $recurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        if (!isset($recurrent)) {
            throw new \Exception("Unable to downgrade subscription. No recurrent payment for parent payment ID [{$payment->id}] found.");
        }

        if (!isset($recurrent->next_subscription_type_id)) {
            Debugger::log("No downgrade for recurrent payment ID [{$recurrent->id}] in the past.", ILogger::WARNING);
            return $recurrent->parent_payment;
        }

        $this->recurrentPaymentsRepository->update($recurrent, [
            'next_subscription_type_id' => null,
            'charge_at' => $payment->subscription->end_time,
        ]);

        return $recurrent->parent_payment;
    }

    private function changeRenewalStatus(
        TransactionInfo $transactionInfo,
        string $notificationSubType,
    ): ActiveRow {
        $this->checkQuantity($transactionInfo);

        $lastPayment = $this->findLastPaymentByOriginalTransactionId($transactionInfo->getOriginalTransactionId());
        if (!isset($lastPayment)) {
            throw new MissingPaymentException("Unable to find (recurrent or non-recurrent) payment with `original_transaction_id` [{$transactionInfo->getOriginalTransactionId()}]. Unable to change renewal status.");
        }

        $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);
        if (!$lastRecurrentPayment) {
            Debugger::log("Missing recurrent payment for parent payment ID: [{$lastPayment->id}]");
            return $lastPayment;
        }

        if ($notificationSubType === ResponseBodyV2::SUBTYPE__AUTO_RENEW_ENABLED) {
            if ($this->recurrentPaymentsRepository->isStopped($lastRecurrentPayment)) {
                // subscription should renew but recurrent payment is stopped; reactivate it
                $this->recurrentPaymentsRepository->reactivateByUser($lastRecurrentPayment->id, $lastRecurrentPayment->user_id);
            }
        } elseif ($notificationSubType === ResponseBodyV2::SUBTYPE__AUTO_RENEW_DISABLED) {
            // subscription shouldn't renew but recurrent payment is active; stop it
            if ($lastRecurrentPayment->state === RecurrentPaymentStateEnum::Active->value) {
                $this->recurrentPaymentsRepository->stoppedBySystem($lastRecurrentPayment->id);
            }
        } else {
            throw new \Exception("Unknown notification subtype [{$notificationSubType}]");
        }

        return $lastPayment;
    }

    private function handleExpired(TransactionInfo $transactionInfo)
    {
        $lastPayment = $this->findLastPaymentByOriginalTransactionId($transactionInfo->getOriginalTransactionId());
        if (!isset($lastPayment)) {
            throw new MissingPaymentException("Unable to find (recurrent or non-recurrent) payment with `original_transaction_id` [{$transactionInfo->getOriginalTransactionId()}].");
        }

        $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);
        if (!isset($lastRecurrentPayment)) {
            // no recurrent payment for last failed charge attempt
            if ($lastPayment->status === PaymentStatusEnum::Fail->value) {
                return $lastPayment;
            }

            throw new MissingPaymentException("Unable to find recurrent payment for parent payment ID: [{$lastPayment->id}].");
        }

        if ($lastRecurrentPayment->state === RecurrentPaymentStateEnum::Active->value) {
            $this->recurrentPaymentsRepository->stoppedBySystem($lastRecurrentPayment->id);
        }

        return $lastPayment;
    }

    private function handleFailedRenewal(
        RenewalInfo $renewalInfo,
        ?string $subtype,
    ) {
        $lastPayment = $this->findLastPaymentByOriginalTransactionId($renewalInfo->getOriginalTransactionId());
        if (!isset($lastPayment)) {
            throw new \Exception("Unable to find (recurrent or non-recurrent) payment with `original_transaction_id` [{$renewalInfo->getOriginalTransactionId()}]. Unable to handle renewal failure.");
        }

        // add free subscription with grace period if user doesn't already have one
        if ($subtype === ResponseBodyV2::SUBTYPE__GRACE_PERIOD) {
            $gracePeriodEndDate = $this->getGracePeriodEndDate($renewalInfo);
            if (!isset($gracePeriodEndDate)) {
                Debugger::log("Unable to get grace period end date for failed renewal with same `original_transaction_id` [{$renewalInfo->getOriginalTransactionId()}].", Debugger::ERROR);
                return $lastPayment;
            }
            $gracePeriodSubscription = $this->subscriptionsRepository->getTable()
                ->where('user_id = ?', $lastPayment->user_id)
                ->where('end_time = ?', $gracePeriodEndDate)
                ->fetch();

            if (!$gracePeriodSubscription) {
                $this->subscriptionsRepository->add(
                    $lastPayment->subscription_type,
                    false,
                    false,
                    $lastPayment->user,
                    SubscriptionsRepository::TYPE_FREE,
                    $this->getNow(),
                    $gracePeriodEndDate,
                    "Created based on Apple-requested grace period",
                    null,
                    false,
                );
            }
        }

        // stop recurrent payment, if the intent is not to continue with subscription
        if (in_array($renewalInfo->getExpirationIntent(), [
            RenewalInfo::EXPIRATION_INTENT__CANCEL,
            RenewalInfo::EXPIRATION_INTENT__PRICE_INCREASE,
            RenewalInfo::EXPIRATION_INTENT__UNAVAILABLE_PRODUCT,
        ], true)) {
            $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);
            $this->recurrentPaymentsRepository->stoppedBySystem($lastRecurrentPayment->id);
        }

        return $lastPayment;
    }

    private function handleRefund(TransactionInfo $transactionInfo): ActiveRow
    {
        $payment = $this->findPaymentByTransactionId($transactionInfo->getTransactionId());
        if (!$payment) {
            throw new \Exception("Unable to find payment with 'transaction_id' [{$transactionInfo->getTransactionId()}].");
        }

        // refund payment only prepaid payment
        if ($payment->status === PaymentStatusEnum::Prepaid->value) {
            $this->paymentsRepository->updateStatus(
                payment: $payment,
                status: PaymentStatusEnum::Refund->value,
                note: "{$payment->note}, Refunded by Apple notification (REFUND)",
            );
        }

        // edit subscription end time
        $subscription = $payment->subscription;
        if (!$subscription) {
            throw new \Exception("No subscription related to payment with ID: [{$payment->id}].");
        }

        $cancellationDate = $this->getCancellationDate($transactionInfo);
        if ($subscription->end_time > $cancellationDate) {
            $this->subscriptionsRepository->update($subscription, [
                'end_time' => $cancellationDate,
                'note' => '[AppleStore - REFUND] Original end_time: ' . $subscription->end_time,
            ]);
        }

        // stop related recurrent payment
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        if (!$recurrentPayment) {
            throw new MissingPaymentException("Unable to find recurrent payment for parent payment ID: [{$payment->id}].");
        }

        if ($recurrentPayment->state === RecurrentPaymentStateEnum::Active->value) {
            $this->recurrentPaymentsRepository->stoppedBySystem($recurrentPayment->id);
        }

        return $payment;
    }

    public function handleRefundReversed(TransactionInfo $transactionInfo): ActiveRow
    {
        $payment = $this->findPaymentByTransactionId($transactionInfo->getTransactionId());
        if (!$payment) {
            throw new \Exception("Unable to find payment with 'transaction_id' [{$transactionInfo->getTransactionId()}].");
        }

        // update payment from refund to prepaid - direct update to prevent event handling
        if ($payment->status === PaymentStatusEnum::Refund->value) {
            $this->paymentsRepository->update($payment, [
                'status' => PaymentStatusEnum::Prepaid->value,
                'note' => 'Prepaid by Apple notification (REFUND_REVERSED)',
            ]);
        }

        // edit subscription end time
        $subscription = $payment->subscription;
        if (!$subscription) {
            throw new \Exception("No subscription related to payment with ID: [{$payment->id}].");
        }

        $this->subscriptionsRepository->update($subscription, [
            'end_time' => $payment->subscription_end_at,
            'note' => '[AppleStore - REFUND_REVERSED] Original end_time: ' . $subscription->end_time,
        ]);

        // reactivate only last related recurrent payment
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        if (!$recurrentPayment) {
            throw new MissingPaymentException("Unable to find recurrent payment for parent payment ID: [{$payment->id}].");
        }

        $lastPayment = $this->findLastPaymentByOriginalTransactionId($transactionInfo->getOriginalTransactionId());
        if (!$lastPayment) {
            throw new \Exception("Unable to find payment with 'original_transaction_id' [{$transactionInfo->getOriginalTransactionId()}].");
        }
        $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);

        if ($lastRecurrentPayment && $lastRecurrentPayment->id === $recurrentPayment->id &&  $lastRecurrentPayment->state === RecurrentPaymentStateEnum::SystemStop->value) {
            $this->recurrentPaymentsRepository->reactivateByUser($recurrentPayment, $recurrentPayment->user_id);
        }

        return $payment;
    }

    private function checkQuantity(TransactionInfo $transactionInfo): void
    {
        // only one subscription per purchase
        if ($transactionInfo->getQuantity() !== 1) {
            throw new \Exception("Unable to handle `quantity` different than 1 for notification with OriginalTransactionId " .
                "[{$transactionInfo->getOriginalTransactionId()}]");
        }
    }

    private function preparePaymentMetas(TransactionInfo $transactionInfo): array
    {
        return [
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID => $transactionInfo->getOriginalTransactionId(),
            AppleAppstoreModule::META_KEY_PRODUCT_ID => $transactionInfo->getProductId(),
            AppleAppstoreModule::META_KEY_TRANSACTION_ID => $transactionInfo->getTransactionId(),
        ];
    }

    private function findLastPaymentByOriginalTransactionId(string $originalTransactionId, ?ActiveRow $subscriptionType = null): ?ActiveRow
    {
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId,
        );
        if (!$paymentMetas) {
            return null;
        }

        if ($subscriptionType) {
            foreach ($paymentMetas as $paymentMeta) {
                if ($subscriptionType->id === $paymentMeta->payment->subscription_type_id) {
                    return $paymentMeta->payment;
                }
            }
            return null;
        }

        // get last payment
        return reset($paymentMetas)->payment;
    }

    private function findPaymentByTransactionId(string $transactionId): ?ActiveRow
    {
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $transactionId,
        );
        if (!$paymentMetas) {
            return null;
        }
        if (count($paymentMetas) > 1) {
            throw new \Exception("Multiple payments with the same transaction ID [{$transactionId}].");
        }

        return reset($paymentMetas)->payment;
    }

    private function getPaymentGatewayByCode(string $code): ActiveRow
    {
        $paymentGateway = $this->paymentGatewaysRepository->findByCode($code);
        if (!$paymentGateway) {
            throw new \Exception("Unable to find PaymentGateway with code [{$code}].");
        }

        return $paymentGateway;
    }
}
