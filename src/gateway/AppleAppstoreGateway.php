<?php

namespace Crm\AppleAppstoreModule\Gateways;

use Crm\AppleAppstoreModule\Model\AppleAppstoreValidatorFactory;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreOriginalTransactionsRepository;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreSubscriptionTypesRepository;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\PaymentsModule\Gateways\ExternallyChargedRecurrentPaymentInterface;
use Crm\PaymentsModule\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Gateways\RecurrentPaymentInterface;
use Crm\PaymentsModule\RecurrentPaymentFailStop;
use Crm\PaymentsModule\RecurrentPaymentFailTry;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Nette\Application\LinkGenerator;
use Nette\Http\Response;
use Nette\Localization\ITranslator;
use ReceiptValidator\iTunes\PurchaseItem;
use ReceiptValidator\iTunes\ResponseInterface;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * AppleAppstoreGateway integrates recurring subscriptions into CRM.
 *
 * Note: `original_transaction_id` is used as recurrent token because `receipt` contains all subscriptions
 * and won't fit `recurrent_payment.cid` column.
 */
class AppleAppstoreGateway extends GatewayAbstract implements RecurrentPaymentInterface, ExternallyChargedRecurrentPaymentInterface
{
    const GATEWAY_CODE = 'apple_appstore';
    const GATEWAY_NAME = 'Apple AppStore';

    private $successful = false;

    private $appleAppstoreValidatorFactory;

    private $appleAppstoreSubscriptionTypesRepository;

    private $appleAppstoreOriginalTransactionsRepository;

    private $recurrentPaymentsRepository;

    private $paymentsRepository;

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
        AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        AppleAppstoreOriginalTransactionsRepository $appleAppstoreOriginalTransactionsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        PaymentsRepository $paymentsRepository
    ) {
        parent::__construct($linkGenerator, $applicationConfig, $httpResponse, $translator);
        $this->appleAppstoreValidatorFactory = $appleAppstoreValidatorFactory;
        $this->appleAppstoreSubscriptionTypesRepository = $appleAppstoreSubscriptionTypesRepository;
        $this->appleAppstoreOriginalTransactionsRepository = $appleAppstoreOriginalTransactionsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->paymentsRepository = $paymentsRepository;
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

        $receipt = $this->appleAppstoreOriginalTransactionsRepository->findByOriginalTransactionId($originalTransactionID);

        try {
            $this->initialize();
            $this->appleAppstoreResponse = $this->appleAppstoreValidator
                ->setReceiptData($receipt->latest_receipt)
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
        $receipt = $this->appleAppstoreOriginalTransactionsRepository->findByOriginalTransactionId($originalTransactionID);
        if (!$receipt) {
            throw new RecurrentPaymentFailStop('Unable to find receipt for given original transaction ID: ' . $originalTransactionID);
        }

        try {
            $this->initialize();
            $appstoreValidator = $this->appleAppstoreValidatorFactory->create();
            $this->appleAppstoreResponse = $appstoreValidator
                ->setReceiptData($receipt->latest_receipt)
                ->setExcludeOldTransactions(true)
                ->validate();
        } catch (\Exception | \GuzzleHttp\Exception\GuzzleException $e) {
            Debugger::log(
                "Unable to validate Apple AppStore receipt [" . $receipt . "] loaded from original transaction ID [" . $originalTransactionID . "]. Error: [{$e->getMessage()}]",
                Debugger::INFO
            );
            throw new RecurrentPaymentFailTry("Unable to validate apple subscription for original transaction ID: " . $originalTransactionID);
        }

        if (!$this->appleAppstoreResponse->isValid()) {
            throw new RecurrentPaymentFailTry(
                "Unable to validate Apple AppStore receipt loaded from original transaction ID [" . $originalTransactionID . "], received code: " . $this->appleAppstoreResponse->getResultCode()
            );
        }
        if ($this->appleAppstoreResponse->getResultCode() === ResponseInterface::RESULT_RECEIPT_VALID_BUT_SUB_EXPIRED) {
            // not throwing "stop" intentionally; it could have expired, but Apple still can try to charge the user
            throw new RecurrentPaymentFailTry(
                "Apple subscription for original transaction ID [" . $originalTransactionID . "] expired"
            );
        }

        // TODO: shouldn't we check pending operations?
        $latestReceipt = $this->getLatestReceiptInfo();
        if ($latestReceipt->getExpiresDate() === null) {
            Debugger::log("Latest receipt returned by Apple is missing expires_date, provided for original transaction ID: " . $originalTransactionID, ILogger::ERROR);
            throw new RecurrentPaymentFailTry();
        }

        // load product of previous purchase; this is needed to find info in pending
        $productID = $latestReceipt['product_id'];

        $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository->findSubscriptionTypeByAppleAppstoreProductId($productID);
        if (!$subscriptionType) {
            // TODO: can be this fixed before next tries?
            Debugger::log("Unable to find SubscriptionType by product ID [{$productID}] provided by ServerToServerNotification.", ILogger::ERROR);
            throw new RecurrentPaymentFailTry();
        }

        // load end_date of last subscription
        $recurrentPayment = $this->recurrentPaymentsRepository->findByPayment($payment);
        if (!$recurrentPayment || !$recurrentPayment->parent_payment_id || !$recurrentPayment->parent_payment->subscription_id) {
            // TODO: can be this fixed before next tries?
            Debugger::log("Unable to find previous subscription for payment ID [{$payment->id}], cannot determine if it was renewed.", ILogger::ERROR);
            throw new RecurrentPaymentFailTry();
        }

        $subscriptionEndDate = $recurrentPayment->parent_payment->subscription->end_time;
        $receiptExpiration = $this->getLatestReceiptExpiration();
        if ($receiptExpiration <= $subscriptionEndDate || $receiptExpiration < new \DateTime()) {
            throw new RecurrentPaymentFailTry();
        }

        // make sure the created subscription matches Apple's purchase/expiration dates
        $this->paymentsRepository->update($payment, [
            'subscription_start_at' => $this->getLatestReceiptPurchaseDate(),
            'subscription_end_at' => $receiptExpiration,
        ]);

        // TODO: check if receipt's product isn't different; if it is, possibly update the payment

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
        if (!isset($this->appleAppstoreResponse)) {
            return null;
        }
        return (string) $this->appleAppstoreResponse->getResultCode();
    }

    public function getResultMessage()
    {
        if (!$this->appleAppstoreResponse) {
            Debugger::log(
                'Missing response from Apple AppStore. Call complete() or checkValid() before loading token.',
                Debugger::ERROR
            );
            return 'purchase_unverified';
        }
        // TODO: check constants in \ReceiptValidator\iTunes\ResponseInterface & return message?
        if (!isset($this->appleAppstoreResponse)) {
            return null;
        }
        return (string) $this->appleAppstoreResponse->getResultCode();
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function isCancelled()
    {
        return false;
    }

    public function isNotSettled()
    {
        return false;
    }

    public function getChargedPaymentStatus(): string
    {
        return PaymentsRepository::STATUS_PREPAID;
    }

    public function getLatestReceiptExpiration(): \DateTime
    {
        return (clone $this->getLatestReceiptInfo()->getExpiresDate())
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    public function getLatestReceiptPurchaseDate(): \DateTime
    {
        return (clone $this->getLatestReceiptInfo()->getPurchaseDate())
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    protected function getLatestReceiptInfo(): PurchaseItem
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
        return $latestReceipt;
    }
}
