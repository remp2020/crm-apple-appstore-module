<?php

namespace Crm\AppleAppstoreModule\Hermes;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Model\DoNotRetryException;
use Crm\AppleAppstoreModule\Model\LatestReceiptInfo;
use Crm\AppleAppstoreModule\Model\PendingRenewalInfo;
use Crm\AppleAppstoreModule\Model\ServerToServerNotification;
use Crm\AppleAppstoreModule\Model\ServerToServerNotificationProcessorInterface;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreOriginalTransactionsRepository;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreServerToServerNotificationLogRepository;
use Crm\ApplicationModule\NowTrait;
use Crm\ApplicationModule\RedisClientFactory;
use Crm\ApplicationModule\RedisClientTrait;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\RecurrentPaymentsProcessor;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;
use Tracy\Debugger;
use malkusch\lock\mutex\PredisMutex;

class ServerToServerNotificationWebhookHandler implements HandlerInterface
{
    use RedisClientTrait;
    use RetryTrait;
    use NowTrait;

    public const INFO_LOG_LEVEL = 'apple_s2s_notifications';

    private $paymentGatewaysRepository;

    private $paymentMetaRepository;

    private $paymentsRepository;

    private $recurrentPaymentsRepository;

    private $serverToServerNotificationLogRepository;

    private $serverToServerNotificationProcessor;

    private $subscriptionsRepository;

    private $appleAppstoreOriginalTransactionsRepository;

    private $recurrentPaymentsProcessor;

    public function __construct(
        ServerToServerNotificationProcessorInterface $serverToServerNotificationProcessor,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentMetaRepository $paymentMetaRepository,
        PaymentsRepository $paymentsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        AppleAppstoreServerToServerNotificationLogRepository $serverToServerNotificationLogRepository,
        RedisClientFactory $redisClientFactory,
        AppleAppstoreOriginalTransactionsRepository $appleAppstoreOriginalTransactionsRepository,
        RecurrentPaymentsProcessor $recurrentPaymentsProcessor
    ) {
        $this->serverToServerNotificationProcessor = $serverToServerNotificationProcessor;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->serverToServerNotificationLogRepository = $serverToServerNotificationLogRepository;
        $this->redisClientFactory = $redisClientFactory;
        $this->appleAppstoreOriginalTransactionsRepository = $appleAppstoreOriginalTransactionsRepository;
        $this->recurrentPaymentsProcessor = $recurrentPaymentsProcessor;
    }

    public function handle(MessageInterface $message): bool
    {
        $payload = $message->getPayload();
        $notification = Json::decode(Json::encode($payload['notification'])); // deep cast to object
        return $this->process($notification);
    }

    private function process($parsedNotification): bool
    {
        try {
            $stsNotification = new ServerToServerNotification($parsedNotification);
            $latestReceiptInfo = $this->serverToServerNotificationProcessor->getLatestLatestReceiptInfo($stsNotification);

            // log notification
            $stsNotificationLog = $this->serverToServerNotificationLogRepository->add(
                Json::encode($parsedNotification),
                $latestReceiptInfo->getOriginalTransactionId()
            );

            // upsert original transaction
            $this->appleAppstoreOriginalTransactionsRepository->add(
                $latestReceiptInfo->getOriginalTransactionId(),
                $stsNotification->getUnifiedReceipt()->getLatestReceipt()
            );

            $mutex = new PredisMutex(
                [$this->redis()],
                'process_apple_transaction_id_' . $latestReceiptInfo->getTransactionId(),
                20
            );

            // Mutex to avoid app and S2S notification procession collision (and therefore e.g. multiple payments to be created)
            $payment = $mutex->synchronized(function () use ($latestReceiptInfo, $stsNotification, $stsNotificationLog) {
                $isTransactionProcessed = $this->paymentMetaRepository->findByMeta(
                    AppleAppstoreModule::META_KEY_TRANSACTION_ID,
                    $latestReceiptInfo->getTransactionId()
                );

                switch ($stsNotification->getNotificationType()) {
                    case ServerToServerNotification::NOTIFICATION_TYPE_INITIAL_BUY:
                        if ($isTransactionProcessed) {
                            throw new DoNotRetryException();
                        }
                        return $this->createPayment($latestReceiptInfo);

                    case ServerToServerNotification::NOTIFICATION_TYPE_CANCEL:
                        return $this->cancelPayment($latestReceiptInfo);

                    case ServerToServerNotification::NOTIFICATION_TYPE_RENEWAL:
                    case ServerToServerNotification::NOTIFICATION_TYPE_DID_RENEW:
                    case ServerToServerNotification::NOTIFICATION_TYPE_DID_RECOVER:
                    case ServerToServerNotification::NOTIFICATION_TYPE_INTERACTIVE_RENEWAL:
                        if ($isTransactionProcessed) {
                            throw new DoNotRetryException();
                        }
                        return $this->createRenewedPayment($latestReceiptInfo);

                    case ServerToServerNotification::NOTIFICATION_TYPE_DID_CHANGE_RENEWAL_PREF:
                        return $this->changeSubscriptionTypeOfNextPayment($latestReceiptInfo);

                    case ServerToServerNotification::NOTIFICATION_TYPE_DID_CHANGE_RENEWAL_STATUS:
                        try {
                            return $this->changeRenewalStatus($stsNotification, $latestReceiptInfo);
                        } catch (MissingPaymentException $exception) {
                            Debugger::log($exception->getMessage(), Debugger::ERROR);
                            // if payment is missing, fallback is to create payment again
                            return $this->createPayment($latestReceiptInfo);
                        }

                    case ServerToServerNotification::NOTIFICATION_TYPE_DID_FAIL_TO_RENEW:
                        $this->handleFailedRenewal($stsNotification, $latestReceiptInfo);
                        return null;

                    default:
                        $this->serverToServerNotificationLogRepository->changeStatus(
                            $stsNotificationLog,
                            AppleAppstoreServerToServerNotificationLogRepository::STATUS_ERROR
                        );
                        $errorMessage = "Unknown `notification_type` [{$stsNotification->getNotificationType()}].";
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
                $payment
            );
            return true;
        } catch (DoNotRetryException $e) {
            // log info and return 200 OK status so Apple won't try to send it again
            Debugger::log($e, self::INFO_LOG_LEVEL);
            $this->serverToServerNotificationLogRepository->changeStatus(
                $stsNotificationLog,
                AppleAppstoreServerToServerNotificationLogRepository::STATUS_DO_NOT_RETRY
            );
            return true;
        }
    }

    /**
     * Create payment from Apple's AppStore ServerToServerNotification
     *
     * @return ActiveRow Payment
     * @throws \Exception Thrown when quantity is different than '1'. Only one subscription per purchase is allowed.
     * @throws DoNotRetryException Thrown by ServerToServerNotificationProcessor when processing failed and it shouldn't be retried.
     */
    private function createPayment(LatestReceiptInfo $latestReceiptInfo): ActiveRow
    {
        // only one subscription per purchase
        if ($latestReceiptInfo->getQuantity() !== 1) {
            throw new \Exception("Unable to handle `quantity` different than 1 for notification with OriginalTransactionId " .
                "[{$latestReceiptInfo->getOriginalTransactionId()}]");
        }

        $metas = [
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID => $latestReceiptInfo->getOriginalTransactionId(),
            AppleAppstoreModule::META_KEY_PRODUCT_ID => $latestReceiptInfo->getProductId(),
            AppleAppstoreModule::META_KEY_TRANSACTION_ID => $latestReceiptInfo->getTransactionId(),
        ];

        $subscriptionType = $this->serverToServerNotificationProcessor->getSubscriptionType($latestReceiptInfo);
        $recurrentCharge = false;
        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));

        $paymentGatewayCode = AppleAppstoreGateway::GATEWAY_CODE;
        $paymentGateway = $this->paymentGatewaysRepository->findByCode($paymentGatewayCode);
        if (!$paymentGateway) {
            throw new \Exception("Unable to find PaymentGateway with code [{$paymentGatewayCode}].");
        }

        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $paymentGateway,
            $this->serverToServerNotificationProcessor->getUser($latestReceiptInfo),
            $paymentItemContainer,
            '',
            $subscriptionType->price,
            $this->serverToServerNotificationProcessor->getSubscriptionStartAt($latestReceiptInfo),
            $this->serverToServerNotificationProcessor->getSubscriptionEndAt($latestReceiptInfo),
            null,
            0,
            null,
            null,
            null,
            $recurrentCharge,
            $metas
        );

        $payment = $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PREPAID);

        // handle recurrent payment
        // - original_transaction_id will be used as recurrent token
        // - stop any previous recurrent payments with the same original transaction id

        $activeOriginalTransactionRecurrents = $this->recurrentPaymentsRepository
            ->getUserActiveRecurrentPayments($payment->user_id)
            ->where(['cid' => $latestReceiptInfo->getOriginalTransactionId()])
            ->fetchAll();
        foreach ($activeOriginalTransactionRecurrents as $rp) {
            $this->recurrentPaymentsRepository->stoppedBySystem($rp->id);
        }

        $this->recurrentPaymentsRepository->createFromPayment(
            $payment,
            $latestReceiptInfo->getOriginalTransactionId()
        );

        return $payment;
    }

    /**
     * @param ServerToServerNotification $stsNotification
     * @return ActiveRow Cancelled payment
     * @throws \Exception Thrown when no payment with `original_transaction_id` is found.
     */
    private function cancelPayment(LatestReceiptInfo $latestReceiptInfo): ActiveRow
    {
        $originalTransactionId = $latestReceiptInfo->getOriginalTransactionId();
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId
        );
        if (!$paymentMetas) {
            throw new \Exception("Unable to cancel subscription. No payment with `original_transaction_id` [{$originalTransactionId}] found.");
        }
        // get last payment
        $paymentMeta = reset($paymentMetas);

        $cancellationDate = $this->serverToServerNotificationProcessor->getCancellationDate($latestReceiptInfo);

        // TODO: should this be refund? or we need new status PREPAID_REFUND?
        $payment = $this->paymentsRepository->updateStatus(
            $paymentMeta->payment,
            PaymentsRepository::STATUS_REFUND,
            true,
            "Cancelled by customer via Apple's Helpdesk. Date [{$cancellationDate}]."
        );
        if ($payment->subscription) {
            $this->subscriptionsRepository->update($payment->subscription, ['end_time' => $cancellationDate]);
        }
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_CANCELLATION_DATE,
            $cancellationDate
        );
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_CANCELLATION_REASON,
            $latestReceiptInfo->getCancellationReason()
        );

        // stop active recurrent
        $recurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        if (!$recurrent || $recurrent->state !== RecurrentPaymentsRepository::STATE_ACTIVE) {
            $lastRecurrent = $this->recurrentPaymentsRepository->getLastWithState($recurrent, RecurrentPaymentsRepository::STATE_ACTIVE);
            if ($lastRecurrent) {
                $recurrent = $lastRecurrent;
            }
        }
        if ($recurrent) {
            // payment was stopped by user through Apple helpdesk
            $this->recurrentPaymentsRepository->update($recurrent, [
                'state' => RecurrentPaymentsRepository::STATE_USER_STOP
            ]);
        } else {
            Debugger::log("Cancelled Apple AppStore payment [{$payment->id}] doesn't have active recurrent payment.", Debugger::WARNING);
        }

        return $payment;
    }

    /**
     * @param ServerToServerNotification $stsNotification
     * @return ActiveRow
     * @throws DoNotRetryException Thrown by ServerToServerNotificationProcessor when processing failed and it shouldn't be retried.
     */
    private function createRenewedPayment(LatestReceiptInfo $latestReceiptInfo): ActiveRow
    {
        // only one subscription per purchase
        if ($latestReceiptInfo->getQuantity() !== 1) {
            throw new \Exception("Unable to handle `quantity` different than 1 for notification with OriginalTransactionId " .
                "[{$latestReceiptInfo->getOriginalTransactionId()}]");
        }

        $originalTransactionID = $latestReceiptInfo->getOriginalTransactionId();
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID
        );

        $lastPayment = reset($paymentMetas)->payment;
        $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);

        $subscriptionStartAt = $this->serverToServerNotificationProcessor->getSubscriptionStartAt($latestReceiptInfo);
        $subscriptionEndAt = $this->serverToServerNotificationProcessor->getSubscriptionEndAt($latestReceiptInfo);
        $subscriptionType = $this->serverToServerNotificationProcessor->getSubscriptionType($latestReceiptInfo);

        if (empty($paymentMetas)) {
            Debugger::log("Unable to find previous payment for renewal with same `original_transaction_id` [{$originalTransactionID}].", Debugger::ERROR);
        } else {
            $lastPayment = reset($paymentMetas)->payment;
            if ($lastPayment->subscription_end_at > $subscriptionStartAt) {
                throw new \Exception("Purchased payment starts [{$subscriptionStartAt}] before previous subscription ends [{$lastPayment->subscription_end_at}].");
            }
        }

        $metas = [
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID => $originalTransactionID,
            AppleAppstoreModule::META_KEY_PRODUCT_ID => $latestReceiptInfo->getProductId(),
            AppleAppstoreModule::META_KEY_TRANSACTION_ID => $latestReceiptInfo->getTransactionId(),
        ];

        $recurrentCharge = true;
        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));

        $paymentGatewayCode = AppleAppstoreGateway::GATEWAY_CODE;
        $paymentGateway = $this->paymentGatewaysRepository->findByCode($paymentGatewayCode);
        if (!$paymentGateway) {
            throw new \Exception("Unable to find PaymentGateway with code [{$paymentGatewayCode}].");
        }

        $payment = $this->paymentsRepository->add(
            $subscriptionType,
            $paymentGateway,
            $this->serverToServerNotificationProcessor->getUser($latestReceiptInfo),
            $paymentItemContainer,
            '',
            $subscriptionType->price,
            $subscriptionStartAt,
            $subscriptionEndAt,
            null,
            0,
            null,
            null,
            null,
            $recurrentCharge,
            $metas
        );

        if ($lastRecurrentPayment) {
            $this->recurrentPaymentsRepository->update($lastRecurrentPayment, [
                'payment_id' => $payment->id,
            ]);
            $this->recurrentPaymentsProcessor->processChargedRecurrent(
                $lastRecurrentPayment,
                PaymentsRepository::STATUS_PREPAID,
                0,
                'NOTIFICATION',
            );
        } else {
            $payment = $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PREPAID);

            // create recurrent payment; original_transaction_id will be used as recurrent token
            $this->recurrentPaymentsRepository->createFromPayment(
                $payment,
                $latestReceiptInfo->getOriginalTransactionId()
            );
        }

        return $payment;
    }

    private function changeSubscriptionTypeOfNextPayment(LatestReceiptInfo $latestReceiptInfo): ActiveRow
    {
        $paymentGatewayCode = AppleAppstoreGateway::GATEWAY_CODE;
        $paymentGateway = $this->paymentGatewaysRepository->findByCode($paymentGatewayCode);
        if (!$paymentGateway) {
            throw new \Exception("Unable to find PaymentGateway with code [{$paymentGatewayCode}].");
        }

        // only one subscription per purchase
        if ($latestReceiptInfo->getQuantity() !== 1) {
            throw new \Exception("Unable to handle `quantity` different than 1 for notification with OriginalTransactionId " .
                "[{$latestReceiptInfo->getOriginalTransactionId()}]");
        }

        $lastRecurrentWithOriginalTransactionID = $this->recurrentPaymentsRepository->getTable()->where([
            'cid' => $latestReceiptInfo->getOriginalTransactionId(),
            'state' => RecurrentPaymentsRepository::STATE_ACTIVE,
        ])->order('charge_at DESC')->fetch();

        if (!$lastRecurrentWithOriginalTransactionID) {
            throw new \Exception("Unable to find recurrent payment with OriginalTransactionId (CID) " .
                "[{$latestReceiptInfo->getOriginalTransactionId()}]");
        }

        // update subscription type for next charge payment
        $subscriptionType = $this->serverToServerNotificationProcessor->getSubscriptionType($latestReceiptInfo);
        $nextChargeAt = $this->serverToServerNotificationProcessor->getSubscriptionEndAt($latestReceiptInfo);
        $this->recurrentPaymentsRepository->update(
            $lastRecurrentWithOriginalTransactionID,
            [
                'next_subscription_type_id' => $subscriptionType->id,
                'charge_at' => $nextChargeAt,
            ]
        );

        return $lastRecurrentWithOriginalTransactionID->parent_payment;
    }

    private function changeRenewalStatus(
        ServerToServerNotification $serverToServerNotification,
        LatestReceiptInfo $latestReceiptInfo
    ): ActiveRow {
        $paymentGatewayCode = AppleAppstoreGateway::GATEWAY_CODE;
        $paymentGateway = $this->paymentGatewaysRepository->findByCode($paymentGatewayCode);
        if (!$paymentGateway) {
            throw new \Exception("Unable to find PaymentGateway with code [{$paymentGatewayCode}].");
        }

        // only one subscription per purchase
        if ($latestReceiptInfo->getQuantity() !== 1) {
            throw new \Exception("Unable to handle `quantity` different than 1 for notification with OriginalTransactionId " .
                "[{$latestReceiptInfo->getOriginalTransactionId()}]");
        }

        $shouldRenew = $serverToServerNotification->getAutoRenewStatus();

        // find last payment with same original transaction ID
        $originalTransactionID = $latestReceiptInfo->getOriginalTransactionId();
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID
        );
        if (empty($paymentMetas)) {
            throw new MissingPaymentException("Unable to find (recurrent or non-recurrent) payment with `original_transaction_id` [{$originalTransactionID}]. Unable to change renewal status.");
        }

        $lastPayment = reset($paymentMetas)->payment;
        $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);

        if ($shouldRenew) {
            // subscription should renew but recurrent payment doesn't exist
            if (!$lastRecurrentPayment) {
                // create recurrent payment from existing payment; original_transaction_id will be used as recurrent token
                $this->recurrentPaymentsRepository->createFromPayment(
                    $lastPayment,
                    $latestReceiptInfo->getOriginalTransactionId()
                );
            } elseif ($this->recurrentPaymentsRepository->isStopped($lastRecurrentPayment)) {
                // subscription should renew but recurrent payment is stopped; reactivate it
                $this->recurrentPaymentsRepository->reactivateByUser($lastRecurrentPayment->id, $lastRecurrentPayment->user_id);
            }
        } else {
            // subscription shouldn't renew but recurrent payment is active; stop it
            if ($lastRecurrentPayment && $lastRecurrentPayment->state === RecurrentPaymentsRepository::STATE_ACTIVE) {
                $this->recurrentPaymentsRepository->stoppedBySystem($lastRecurrentPayment->id);
            }
        }

        return $lastPayment;
    }

    private function handleFailedRenewal(
        ServerToServerNotification $serverToServerNotification,
        LatestReceiptInfo $latestReceiptInfo
    ) {
        // find last payment with same original transaction ID
        $originalTransactionID = $latestReceiptInfo->getOriginalTransactionId();
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID
        );

        if (empty($paymentMetas)) {
            throw new \Exception("Unable to find (recurrent or non-recurrent) payment with `original_transaction_id` [{$originalTransactionID}]. Unable to handle renewal failure.");
        }

        $lastPayment = reset($paymentMetas)->payment;

        $pendingRenewalInfo = $this->serverToServerNotificationProcessor
            ->getLatestPendingRenewalInfo($serverToServerNotification);

        // add free subscription with grace period if user doesn't already have one
        if ($gracePeriodEndDate = $this->serverToServerNotificationProcessor->getGracePeriodEndDate($pendingRenewalInfo)) {
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
                    false
                );
            }
        }

        // stop recurrent payment, if the intent is not to continue with subscription
        if (in_array($pendingRenewalInfo->getExpirationIntent(), [
            PendingRenewalInfo::EXPIRATION_INTENT_CANCELLED_SUBSCRIPTION,
            PendingRenewalInfo::EXPIRATION_INTENT_DISAGREE_PRICE_CHANGE,
            PendingRenewalInfo::EXPIRATION_INTENT_PRODUCT_NOT_AVAILABLE_AT_RENEWAL,
        ], true)) {
            $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);
            $this->recurrentPaymentsRepository->stoppedBySystem($lastRecurrentPayment->id);
        }
    }
}
