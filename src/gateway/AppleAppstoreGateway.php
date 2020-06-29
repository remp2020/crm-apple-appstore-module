<?php

namespace Crm\AppleAppstoreModule\Gateways;

use Crm\AppleAppstoreModule\Model\AppleAppstoreValidatorFactory;
use Crm\AppleAppstoreModule\Model\ServerToServerNotification;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreServerToServerNotificationLogRepository;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreSubscriptionTypesRepository;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\PaymentsModule\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Gateways\RecurrentPaymentInterface;
use Crm\PaymentsModule\RecurrentPaymentFailTry;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Nette\Application\LinkGenerator;
use Nette\Http\Response;
use Nette\Localization\ITranslator;
use Tracy\Debugger;

/**
 * AppleAppstoreGateway integrates recurring subscriptions into CRM.
 *
 * Note: `original_transaction_id` is used as recurrent token because `receipt` contains all subscriptions and won't fit `recurrent_payment.cid` column.
 */
class AppleAppstoreGateway extends GatewayAbstract implements RecurrentPaymentInterface
{
    const GATEWAY_CODE = 'apple_appstore';
    const GATEWAY_NAME = 'Apple AppStore';

    private $successful = false;

    private $appleAppstoreValidatorFactory;

    private $appleAppstoreSubscriptionTypesRepository;

    private $appleAppstoreServerToServerNotificationLogRepository;

    private $recurrentPaymentsRepository;

    /** @var \ReceiptValidator\iTunes\Validator */
    private $appleAppstoreValidator;

    /** @var \ReceiptValidator\iTunes\ResponseInterface */
    private $appleAppstoreResponse = null;

    public function __construct(
        LinkGenerator $linkGenerator,
        ApplicationConfig $applicationConfig,
        Response $httpResponse,
        ITranslator $translator,
        AppleAppstoreValidatorFactory $appleAppstoreValidatorFactory,
        AppleAppstoreServerToServerNotificationLogRepository $appleAppstoreServerToServerNotificationLogRepository,
        AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository
    ) {
        parent::__construct($linkGenerator, $applicationConfig, $httpResponse, $translator);
        $this->appleAppstoreValidatorFactory = $appleAppstoreValidatorFactory;
        $this->appleAppstoreServerToServerNotificationLogRepository = $appleAppstoreServerToServerNotificationLogRepository;
        $this->appleAppstoreSubscriptionTypesRepository = $appleAppstoreSubscriptionTypesRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
    }

    protected function initialize()
    {
    }

    public function process($allowRedirect = true)
    {
        throw new \Exception("AppleAppstoreGateway is not intended for use as standard payment gateway.");
    }

    public function begin($payment)
    {
        throw new \Exception("AppleAppstoreGateway is not intended for use as standard payment gateway.");
    }

    public function complete($payment): ?bool
    {
        throw new \Exception("AppleAppstoreGateway is not intended for use as standard payment gateway.");
    }

    public function checkValid($originalTransactionID)
    {
        if ($this->appleAppstoreValidator === null) {
            $this->appleAppstoreValidator = $this->appleAppstoreValidatorFactory->create();
        }

        // find last receipt for original transaction ID in server to server notifications log
        $lastTransactionJson = $this->appleAppstoreServerToServerNotificationLogRepository->findLastByOriginalTransactionID($originalTransactionID);
        $stsNotification = new ServerToServerNotification(json_decode($lastTransactionJson));
        $receipt = $stsNotification->getUnifiedReceipt()->getLatestReceipt();

        try {
            $this->initialize();
            $this->appleAppstoreResponse = $this->appleAppstoreValidator
                ->setReceiptData($receipt)
                ->setExcludeOldTransactions(true)
                ->validate();
        } catch (\Exception | \GuzzleHttp\Exception\GuzzleException $e) {
            Debugger::log(
                "Unable to validate Apple AppStore receipt [" . $receipt . "] loaded from original transaction ID [" . $originalTransactionID . "]. Error: [{$e->getMessage()}]",
                Debugger::INFO
            );
            return false;
        }

        return $this->appleAppstoreResponse->isValid();
    }

    public function checkExpire($recurrentPayments)
    {
        throw new \Exception("AppleAppstore recurrent gateway doesn't support receipt expiration checking (it should never expire)");
    }

    /**
     * @param $payment
     * @param string $originalTransactionID
     * @throws \Crm\PaymentsModule\RecurrentPaymentFailStop
     * @throws \Crm\PaymentsModule\RecurrentPaymentFailTry
     */
    public function charge($payment, $originalTransactionID): string
    {
        $this->checkValid($originalTransactionID);

        $lastTransactionJson = $this->appleAppstoreServerToServerNotificationLogRepository->findLastByOriginalTransactionID($originalTransactionID);
        $stsNotification = new ServerToServerNotification(json_decode($lastTransactionJson));
        $receipt = $stsNotification->getUnifiedReceipt()->getLatestReceipt();
        // load product of previous purchase; this is needed to find info in pending
        $productID = $stsNotification->getUnifiedReceipt()->getLatestReceiptInfo()->getProductId();

        $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository->findSubscriptionTypeByAppleAppstoreProductId($productID);
        if (!$subscriptionType) {
            // TODO: can be this fixed before next tries?
            throw new RecurrentPaymentFailTry("Unable to find SubscriptionType by product ID [{$productID}] provided by ServerToServerNotification.");
        }
//        return $subscriptionType;

        // load end_date of last subscription
        $recurrentPayment = $this->recurrentPaymentsRepository->findByPayment($payment);
        if (!$recurrentPayment || !$recurrentPayment->parent_payment_id || !$recurrentPayment->parent_payment->subscription_id) {
            // TODO: can be this fixed before next tries?
            throw new RecurrentPaymentFailTry("Unable to find previous subscription for payment ID [{$payment->id}], cannot determine if it was renewed.");
        }

        $subscriptionEndDate = $recurrentPayment->parent_payment->subscription->end_time;

        // TODO: shouldn'ŧ we check pending operations?
        $latestReceipt = $this->appleAppstoreResponse->getLatestReceiptInfo();
        if (count($latestReceipt) !== 1) {
            Debugger::log(
                'Apple AppStore returned more than one receipt. Is `exclude_old_transactions` set to true?',
                Debugger::WARNING
            );
        }
        $latestReceipt = reset($latestReceipt);
        if ($latestReceipt->getExpiresDate() === null) {
            throw new RecurrentPaymentFailTry("Lastest receipt returned by Apple is missing expires_date.");
        }

        if (!$latestReceipt->getExpiresDate()->greaterThan($subscriptionEndDate)) {
            throw new RecurrentPaymentFailTry();
        }

        // everything is ok; apple charged customer and subscription was created
        $this->successful = true;

        return RecurrentPaymentInterface::CHARGE_OK;
    }

    public function hasRecurrentToken(): bool
    {
        return !empty($this->getRecurrentToken()) ? true : false;
    }

    /**
     * Load $originalTransactionID from latest receipt of Apple AppStore subscription.
     *
     * @return string $originalTransactionID
     */
    public function getRecurrentToken()
    {
        if (!$this->appleAppstoreResponse) {
            throw new \Exception("Missing response from Apple AppStore. Call complete() or checkValid() before loading token.");
        }

        $latestReceipt = $this->appleAppstoreResponse->getLatestReceiptInfo();
        if (count($latestReceipt) !== 1) {
            Debugger::log(
                'Apple AppStore returned more than one receipt. Is `exclude_old_transactions` set to true?',
                Debugger::WARNING
            );
        }
        $latestReceipt = reset($latestReceipt);

        return $latestReceipt->getOriginalTransactionId();
    }

    public function getResultCode()
    {
        return (string) $this->appleAppstoreResponse->getResultCode();
    }

    public function getResultMessage()
    {
        // TODO: check constants in \ReceiptValidator\iTunes\ResponseInterface & return message?
        return (string) $this->appleAppstoreResponse->getResultCode();
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function isCancelled()
    {
        // TODO: return true if subscription was cancelled
        return false;
    }

    public function isNotSettled()
    {
        // TODO: return true if payment is not renewed yet
        return false;
    }
}
