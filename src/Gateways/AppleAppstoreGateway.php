<?php

namespace Crm\AppleAppstoreModule\Gateways;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Models\AppStoreServerApiFactory;
use Crm\AppleAppstoreModule\Models\AppStoreServerDateTimesTrait;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\PaymentsModule\Models\Gateways\ExternallyChargedRecurrentPaymentInterface;
use Crm\PaymentsModule\Models\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Models\Gateways\RecurrentPaymentInterface;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\RecurrentPaymentFailTry;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Nette\Application\LinkGenerator;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Nette\Localization\Translator;
use Omnipay\Common\Exception\InvalidRequestException;
use Readdle\AppStoreServerAPI\Exception\AppStoreServerAPIException;
use Readdle\AppStoreServerAPI\TransactionInfo;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * AppleAppstoreGateway integrates recurring subscriptions into CRM.
 *
 * Note: `original_transaction_id` is used as recurrent token.
 */
class AppleAppstoreGateway extends GatewayAbstract implements RecurrentPaymentInterface, ExternallyChargedRecurrentPaymentInterface
{
    use AppStoreServerDateTimesTrait;

    public const GATEWAY_CODE = 'apple_appstore';
    public const GATEWAY_NAME = 'Apple AppStore';

    private bool $successful = false;
    private TransactionInfo $transactionInfo;

    public function __construct(
        LinkGenerator $linkGenerator,
        ApplicationConfig $applicationConfig,
        Response $httpResponse,
        Translator $translator,
        private readonly AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        private readonly RecurrentPaymentsRepository $recurrentPaymentsRepository,
        private readonly PaymentsRepository $paymentsRepository,
        private readonly PaymentMetaRepository $paymentMetaRepository,
        private readonly AppStoreServerApiFactory $appStoreServerApiFactory,
    ) {
        parent::__construct($linkGenerator, $applicationConfig, $httpResponse, $translator);
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
        $appStoreServerApi = $this->appStoreServerApiFactory->create();

        try {
            $transactionHistory = $appStoreServerApi->getTransactionHistory($originalTransactionID, ['sort' => 'DESCENDING']);
            $this->transactionInfo = $transactionHistory->getTransactions()->current();
        } catch (AppStoreServerAPIException $e) {
            Debugger::log("Unable to get transaction history from App Store Server Api. Error: [{$e->getMessage()}]", Debugger::INFO);
            throw new RecurrentPaymentFailTry("Unable to get transaction history for original transaction ID: " . $originalTransactionID);
        }

        return !$this->transactionInfo->getRevocationDate();
    }

    public function checkExpire($recurrentPayments)
    {
        throw new InvalidRequestException(self::GATEWAY_CODE . " gateway doesn't support token expiration checking (it should never expire)");
    }

    /**
     * @param $payment
     * @param string $originalTransactionID
     * @throws RecurrentPaymentFailTry
     */
    public function charge($payment, $originalTransactionID): string
    {
        // set original transaction ID to preserve payments chain even if charge fails
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID
        );

        $appStoreServerApi = $this->appStoreServerApiFactory->create();
        try {
            $transactionHistoryResponse = $appStoreServerApi->getTransactionHistory($originalTransactionID, ['sort' => 'DESCENDING']);
            $this->transactionInfo = $transactionHistoryResponse->getTransactions()->current();
        } catch (AppStoreServerAPIException $e) {
            Debugger::log("Unable to get transaction history from App Store Server Api. Error: [{$e->getMessage()}]", Debugger::INFO);
            throw new RecurrentPaymentFailTry("Unable to get transaction history for original transaction ID: " . $originalTransactionID);
        }

        $productID = $this->transactionInfo->getProductId();
        $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository->findSubscriptionTypeByAppleAppstoreProductId($productID);
        if (!$subscriptionType) {
            Debugger::log("Unable to find SubscriptionType by product ID [{$productID}] provided by Transaction info.", ILogger::ERROR);
            throw new RecurrentPaymentFailTry();
        }

        // load end_date of last subscription
        $recurrentPayment = $this->recurrentPaymentsRepository->findByPayment($payment);

        // traverse to the latest successful parent payment
        /** @var ActiveRow $parentPayment */
        $parentPayment = $this->recurrentPaymentsRepository
            ->latestSuccessfulRecurrentPayment($recurrentPayment)
            ->parent_payment ?? null;

        if (!isset($parentPayment->subscription_id)) {
            // TODO: can be this fixed before next tries?
            Debugger::log("Unable to find previous subscription for payment ID [{$payment->id}], cannot determine if it was renewed.", ILogger::ERROR);
            throw new RecurrentPaymentFailTry();
        }

        $subscriptionEndDate = $parentPayment->subscription->end_time;
        $transactionExpiration = $this->getSubscriptionExpiration($originalTransactionID);
        if ($transactionExpiration <= $subscriptionEndDate || $transactionExpiration < new \DateTime()) {
            throw new RecurrentPaymentFailTry();
        }

        // make sure the created subscription matches Apple's purchase/expiration dates
        $this->paymentsRepository->update($payment, [
            'subscription_start_at' => $this->getSubscriptionStartAt($this->getLatestTransactionInfo()),
            'subscription_end_at' => $transactionExpiration,
        ]);

        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_PRODUCT_ID,
            $this->transactionInfo->getProductId()
        );
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $this->transactionInfo->getTransactionId()
        );

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
     * Load $originalTransactionID from the latest receipt of Apple App Store subscription.
     *
     * @return string $originalTransactionID
     */
    public function getRecurrentToken()
    {
        return $this->getLatestTransactionInfo()->getOriginalTransactionId();
    }

    public function getResultCode(): ?string
    {
        return 'OK';
    }

    public function getResultMessage(): ?string
    {
        return 'OK';
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
        return PaymentStatusEnum::Prepaid->value;
    }

    public function getSubscriptionExpiration(string $cid = null): \DateTime
    {
        return $this->getSubscriptionEndAt($this->getLatestTransactionInfo());
    }

    protected function getLatestTransactionInfo(): TransactionInfo
    {
        if (!isset($this->transactionInfo)) {
            throw new \Exception("Missing response from Apple AppStore. Call complete() or checkValid() before loading token.");
        }

        return $this->transactionInfo;
    }
}
