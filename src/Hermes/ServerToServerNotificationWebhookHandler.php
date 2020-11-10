<?php

namespace Crm\AppleAppstoreModule\Hermes;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Model\DoNotRetryException;
use Crm\AppleAppstoreModule\Model\LatestReceiptInfo;
use Crm\AppleAppstoreModule\Model\ServerToServerNotification;
use Crm\AppleAppstoreModule\Model\ServerToServerNotificationProcessorInterface;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreServerToServerNotificationLogRepository;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\RedisClientFactory;
use Crm\ApplicationModule\RedisClientTrait;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use malkusch\lock\mutex\PredisMutex;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Json;
use Tomaj\Hermes\Handler\HandlerInterface;
use Tomaj\Hermes\Handler\RetryTrait;
use Tomaj\Hermes\MessageInterface;
use Tracy\Debugger;

class ServerToServerNotificationWebhookHandler implements HandlerInterface
{
    use RedisClientTrait;
    use RetryTrait;

    public const INFO_LOG_LEVEL = 'apple_s2s_notifications';

    private $applicationConfig;

    private $paymentGatewaysRepository;

    private $paymentMetaRepository;

    private $paymentsRepository;

    private $recurrentPaymentsRepository;

    private $serverToServerNotificationLogRepository;

    private $serverToServerNotificationProcessor;

    private $subscriptionsRepository;

    private $s2sNotificationLog = null;

    public function __construct(
        ServerToServerNotificationProcessorInterface $serverToServerNotificationProcessor,
        ApplicationConfig $applicationConfig,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentMetaRepository $paymentMetaRepository,
        PaymentsRepository $paymentsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        AppleAppstoreServerToServerNotificationLogRepository $serverToServerNotificationLogRepository,
        RedisClientFactory $redisClientFactory
    ) {
        $this->serverToServerNotificationProcessor = $serverToServerNotificationProcessor;

        $this->applicationConfig = $applicationConfig;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->serverToServerNotificationLogRepository = $serverToServerNotificationLogRepository;
        $this->redisClientFactory = $redisClientFactory;
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
            $this->logNotification(Json::encode($parsedNotification), $latestReceiptInfo->getOriginalTransactionId());

            $mutex = new PredisMutex([$this->redis()], 'process_apple_transaction_id_' . $latestReceiptInfo->getTransactionId());

            // Mutex to avoid app and S2S notification procession collision (and therefore e.g. multiple payments to be created)
            $payment = $mutex->synchronized(function () use ($latestReceiptInfo, $stsNotification) {
                $isTransactionProcessed = $this->paymentMetaRepository->findByMeta(
                    AppleAppstoreModule::META_KEY_TRANSACTION_ID,
                    $latestReceiptInfo->getTransactionId()
                );
                if ($isTransactionProcessed) {
                    throw new DoNotRetryException();
                }

                switch ($stsNotification->getNotificationType()) {
                    case ServerToServerNotification::NOTIFICATION_TYPE_INITIAL_BUY:
                        return $this->createPayment($latestReceiptInfo);

                    case ServerToServerNotification::NOTIFICATION_TYPE_CANCEL:
                        return $this->cancelPayment($latestReceiptInfo);

                    case ServerToServerNotification::NOTIFICATION_TYPE_RENEWAL:
                    case ServerToServerNotification::NOTIFICATION_TYPE_DID_RECOVER:
                    case ServerToServerNotification::NOTIFICATION_TYPE_INTERACTIVE_RENEWAL:
                        return $this->createRenewedPayment($latestReceiptInfo);

                    case ServerToServerNotification::NOTIFICATION_TYPE_DID_CHANGE_RENEWAL_PREF:
                        return $this->changeSubscriptionTypeOfNextPayment($latestReceiptInfo);

                    case ServerToServerNotification::NOTIFICATION_TYPE_DID_CHANGE_RENEWAL_STATUS:
                        return $this->changeRenewalStatus($stsNotification, $latestReceiptInfo);

                    default:
                        $this->logNotificationChangeStatus(AppleAppstoreServerToServerNotificationLogRepository::STATUS_ERROR);
                        $errorMessage = "Unknown `notification_type` [{$stsNotification->getNotificationType()}].";
                        Debugger::log($errorMessage, Debugger::ERROR);
                }
                return null;
            });

            if (!$payment) {
                // unknown status, report "error" in processing to hermes
                return false;
            }

            $this->logNotificationPayment($payment);
            return true;
        } catch (DoNotRetryException $e) {
            // log info and return 200 OK status so Apple won't try to send it again
            Debugger::log($e, self::INFO_LOG_LEVEL);
            $this->logNotificationChangeStatus(AppleAppstoreServerToServerNotificationLogRepository::STATUS_DO_NOT_RETRY);
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

        $retries = explode(', ', $this->applicationConfig->get('recurrent_payment_charges'));
        $retries = count($retries);
        $this->recurrentPaymentsRepository->add(
            $latestReceiptInfo->getOriginalTransactionId(),
            $payment,
            $this->recurrentPaymentsRepository->calculateChargeAt($payment),
            null,
            --$retries
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

        $cancellationDate = $this->serverToServerNotificationProcessor->getCancellationDate($latestReceiptInfo)->format("Y-m-d H:i:s");

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

        $payment = $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PREPAID);

        // create recurrent payment; original_transaction_id will be used as recurrent token
        $retries = explode(', ', $this->applicationConfig->get('recurrent_payment_charges'));
        $retries = count($retries);
        $this->recurrentPaymentsRepository->add(
            $latestReceiptInfo->getOriginalTransactionId(),
            $payment,
            $this->recurrentPaymentsRepository->calculateChargeAt($payment),
            null,
            --$retries
        );

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
            throw new \Exception("Unable to find (recurrent or non-recurrent) payment with `original_transaction_id` [{$originalTransactionID}]. Unable to change renewal status.");
        }

        $lastPayment = reset($paymentMetas)->payment;
        $lastRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($lastPayment);

        if ($shouldRenew) {
            // subscription should renew but recurrent payment doesn't exist
            if (!$lastRecurrentPayment) {
                // create recurrent payment from existing payment; original_transaction_id will be used as recurrent token
                $retries = explode(', ', $this->applicationConfig->get('recurrent_payment_charges'));
                $retries = count($retries);
                $this->recurrentPaymentsRepository->add(
                    $latestReceiptInfo->getOriginalTransactionId(),
                    $lastPayment,
                    $this->recurrentPaymentsRepository->calculateChargeAt($lastPayment),
                    null,
                    --$retries
                );
            } elseif ($this->recurrentPaymentsRepository->isStopped($lastRecurrentPayment)) {
                // subscription should renew but recurrent payment is stopped; reactivate it
                $this->recurrentPaymentsRepository->reactiveByUser($lastRecurrentPayment->id, $lastRecurrentPayment->user_id);
            }
        } else {
            // subscription shouldn't renew but recurrent payment is active; stop it
            if ($lastRecurrentPayment && $lastRecurrentPayment->state === RecurrentPaymentsRepository::STATE_ACTIVE) {
                $this->recurrentPaymentsRepository->stoppedByUser($lastRecurrentPayment->id, $lastRecurrentPayment->user_id);
            }
        }

        return $lastPayment;
    }

    private function logNotification(string $notification, string $originalTransactionID)
    {
        if ($this->s2sNotificationLog !== null) {
            Debugger::log("Apple AppStore's ServerToServerNotification already logged.", Debugger::ERROR);
        }

        $s2sNotificationLog = $this->serverToServerNotificationLogRepository->add($notification, $originalTransactionID);
        if (!$s2sNotificationLog) {
            Debugger::log("Unable to log Apple AppStore's ServerToServerNotification", Debugger::ERROR);
        }

        $this->s2sNotificationLog = $s2sNotificationLog;
    }

    private function logNotificationPayment(ActiveRow $payment)
    {
        if ($this->s2sNotificationLog === null) {
            throw new \Exception("No server to server notification found. Call `logNotification` first.");
        }

        $s2sNotificationLog = $this->serverToServerNotificationLogRepository->addPayment($this->s2sNotificationLog, $payment);
        if (!$s2sNotificationLog) {
            Debugger::log("Unable to add Payment to Apple AppStore's ServerToServerNotification log.", Debugger::ERROR);
        }
    }

    private function logNotificationChangeStatus(string $status)
    {
        if ($this->s2sNotificationLog === null) {
            throw new \Exception("No server to server notification found. Call `logNotification` first.");
        }

        $s2sNotificationLog = $this->serverToServerNotificationLogRepository->changeStatus($this->s2sNotificationLog, $status);
        if (!$s2sNotificationLog) {
            Debugger::log("Unable to change status of Apple AppStore's ServerToServerNotification log.", Debugger::ERROR);
        }
    }
}
