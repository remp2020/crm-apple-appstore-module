<?php

namespace Crm\AppleAppstoreModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Api\JsonValidationTrait;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Model\DoNotRetryException;
use Crm\AppleAppstoreModule\Model\LatestReceiptInfo;
use Crm\AppleAppstoreModule\Model\ServerToServerNotification;
use Crm\AppleAppstoreModule\Model\ServerToServerNotificationProcessorInterface;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreServerToServerNotificationLogRepository;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Tracy\Debugger;

class ServerToServerNotificationWebhookApiHandler extends ApiHandler
{
    use JsonValidationTrait;

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
        AppleAppstoreServerToServerNotificationLogRepository $serverToServerNotificationLogRepository
    ) {
        $this->serverToServerNotificationProcessor = $serverToServerNotificationProcessor;

        $this->applicationConfig = $applicationConfig;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->serverToServerNotificationLogRepository = $serverToServerNotificationLogRepository;
    }

    public function params()
    {
        return [];
    }

    public function handle(ApiAuthorizationInterface $authorization)
    {
        // decode & validate ServerToServerNotification
        $request = $this->rawPayload();
        $notification = $this->validateInput(__DIR__ . '/server-to-server-notification.schema.json', $request);
        if ($notification->hasErrorResponse()) {
            return $notification->getErrorResponse();
        }
        $parsedNotification = $notification->getParsedObject();

        try {
            $stsNotification = new ServerToServerNotification($parsedNotification);
            $latestReceiptInfo = $this->serverToServerNotificationProcessor->getLatestLatestReceiptInfo($stsNotification);
            $this->logNotification($request, $latestReceiptInfo->getOriginalTransactionId());

            switch ($stsNotification->getNotificationType()) {
                case ServerToServerNotification::NOTIFICATION_TYPE_INITIAL_BUY:
                    $payment = $this->createPayment($latestReceiptInfo);
                    break;
                case ServerToServerNotification::NOTIFICATION_TYPE_CANCEL:
                    $payment = $this->cancelPayment($latestReceiptInfo);
                    break;
                case ServerToServerNotification::NOTIFICATION_TYPE_RENEWAL:
                case ServerToServerNotification::NOTIFICATION_TYPE_DID_RECOVER:
                    $payment = $this->createRenewedPayment($latestReceiptInfo);
                    break;
                default:
                    $errorMessage = "Unknown `notification_type` [{$stsNotification->getNotificationType()}].";
                    $this->logNotificationChangeStatus(AppleAppstoreServerToServerNotificationLogRepository::STATUS_ERROR);
                    $response = new JsonResponse([
                        'status' => 'error',
                        'result' => $errorMessage,
                    ]);
                    $response->setHttpCode(Response::S400_BAD_REQUEST);
                    return $response;
            }

            if ($payment) {
                $this->logNotificationPayment($payment);
            }
        } catch (DoNotRetryException $e) {
            // log info and return 200 OK status so Apple won't try to send it again
            Debugger::log($e, self::INFO_LOG_LEVEL);
            $this->logNotificationChangeStatus(AppleAppstoreServerToServerNotificationLogRepository::STATUS_DO_NOT_RETRY);
            $response = new JsonResponse([
                'status' => 'ok',
                'result' => 'Server-To-Server Notification acknowledged.',
            ]);
            $response->setHttpCode(Response::S200_OK);
            return $response;
        } catch (\Exception $e) {
            // catching exceptions so we can return json error; otherwise tomaj/nette-api return HTML exception...
            Debugger::log($e, Debugger::EXCEPTION);
            $this->logNotificationChangeStatus(AppleAppstoreServerToServerNotificationLogRepository::STATUS_ERROR);
            $response = new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
            $response->setHttpCode(Response::S500_INTERNAL_SERVER_ERROR);
            return $response;
        }

        $response = new JsonResponse([
            'status' => 'ok',
            'result' => 'Server-To-Server Notification acknowledged.',
        ]);
        $response->setHttpCode(Response::S200_OK);
        return $response;
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
            if (!$lastRecurrent) {
                Debugger::log("Cancelled Apple AppStore payment [{$payment->id}] doesn't have active recurrent payment.", Debugger::WARNING);
            }
            $recurrent = $lastRecurrent;
        }

        // payment was stopped by user through Apple helpdesk
        $this->recurrentPaymentsRepository->update($recurrent, [
            'state' => RecurrentPaymentsRepository::STATE_USER_STOP
        ]);

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
        if (empty($paymentMetas)) {
            // TODO: should we create payment if we didn't find previous payment?
            throw new \Exception("Unable to find previous payment with same `original_transaction_id` [{$originalTransactionID}].");
        }

        $lastPayment = reset($paymentMetas)->payment;
        $subscriptionStartAt = $this->serverToServerNotificationProcessor->getSubscriptionStartAt($latestReceiptInfo);
        $subscriptionEndAt = $this->serverToServerNotificationProcessor->getSubscriptionEndAt($latestReceiptInfo);
        if ($lastPayment->subscription_end_at > $subscriptionStartAt) {
            throw new \Exception("Purchased payment starts [{$subscriptionStartAt}] before previous subscription ends [{$lastPayment->subscription_end_at}].");
        }

        $metas = [
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID => $originalTransactionID,
            AppleAppstoreModule::META_KEY_PRODUCT_ID => $latestReceiptInfo->getProductId(),
            AppleAppstoreModule::META_KEY_TRANSACTION_ID => $latestReceiptInfo->getTransactionId(),
        ];

        $subscriptionType = $this->serverToServerNotificationProcessor->getSubscriptionType($latestReceiptInfo);
        if ($subscriptionType->id !== $lastPayment->subscription_type_id) {
            throw new \Exception("SubscriptionType mismatch. New payment [{$subscriptionType->id}], old payment [$lastPayment->subscription_type_id].");
        }

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
            // TODO: should we remove gap with previous payment?
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
