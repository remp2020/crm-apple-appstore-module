<?php

namespace Crm\AppleAppstoreModule\User;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Model\AppleAppstoreValidatorFactory;
use Crm\AppleAppstoreModule\Model\Config;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreOriginalTransactionsRepository;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\User\UserDataProviderInterface;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Exception;
use Nette\Localization\ITranslator;
use ReceiptValidator\iTunes\PendingRenewalInfo;

class AppleAppstoreUserDataProvider implements UserDataProviderInterface
{
    private $translator;

    private $appleAppstoreValidatorFactory;

    private $configsRepository;

    private $paymentsRepository;

    private $paymentMetaRepository;

    private $appleAppstoreOriginalTransactionsRepository;

    public function __construct(
        AppleAppstoreOriginalTransactionsRepository $appleAppstoreOriginalTransactionsRepository,
        AppleAppstoreValidatorFactory $appleAppstoreValidatorFactory,
        ConfigsRepository $configsRepository,
        ITranslator $translator,
        PaymentsRepository $paymentsRepository,
        PaymentMetaRepository $paymentMetaRepository
    ) {
        $this->appleAppstoreValidatorFactory = $appleAppstoreValidatorFactory;
        $this->configsRepository = $configsRepository;
        $this->translator = $translator;
        $this->paymentsRepository = $paymentsRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->appleAppstoreOriginalTransactionsRepository = $appleAppstoreOriginalTransactionsRepository;
    }

    public static function identifier(): string
    {
        return 'apple_appstore';
    }

    public function data($userId)
    {
        return [];
    }

    public function download($userId)
    {
        return [];
    }

    public function downloadAttachments($userId)
    {
        return [];
    }

    public function delete($userId, $protectedData = [])
    {
        return [];
    }

    public function protect($userId): array
    {
        return [];
    }

    public function canBeDeleted($userId): array
    {
        $configRow = $this->configsRepository->loadByName(Config::APPLE_BLOCK_ANONYMIZATION);
        if (!$configRow || !$configRow->value) {
            return [true, null];
        }
        $userPayments = $this->paymentsRepository->userPayments($userId)->where('payment_gateway.code', AppleAppstoreGateway::GATEWAY_CODE);
        $checked = [];
        foreach ($userPayments as $userPayment) {
            $originalTransactionMeta = $this->paymentMetaRepository->findByPaymentAndKey($userPayment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID);
            if (!$originalTransactionMeta) {
                throw new Exception('Missing original transaction ID for payment ID ' . $userPayment->id);
            }
            if (isset($checked[$originalTransactionMeta->value])) {
                continue;
            }
            $originalTransactionRow = $this->appleAppstoreOriginalTransactionsRepository->findByOriginalTransactionId($originalTransactionMeta->value);
            if (!$originalTransactionRow) {
                throw new Exception('Original transaction ID ' . $originalTransactionMeta->value . ' missing in apple_appstore_original_transactions table');
            }

            $checked[$originalTransactionMeta->value] = true;
            $appleResponse = null;
            try {
                $appleAppStoreValidator = $this->appleAppstoreValidatorFactory->create();
                $appleResponse = $appleAppStoreValidator
                    ->setReceiptData($originalTransactionRow->latest_receipt)
                    ->setExcludeOldTransactions(true)
                    ->validate();
            } catch (\Exception | \GuzzleHttp\Exception\GuzzleException $e) {
                new \Exception("Unable to validate Apple AppStore payment. Error: [{$e->getMessage()}]");
            }

            if (!$appleResponse->isValid()) {
                new \Exception("Apple appstore receipt is not valid: " . $originalTransactionRow->latest_receipt);
            }

            /** @var PendingRenewalInfo $pendingRenewalInfo */
            $pendingRenewalInfoArray = $appleResponse->getPendingRenewalInfo();
            foreach ($pendingRenewalInfoArray as $pendingRenewalInfo) {
                if ($pendingRenewalInfo->getAutoRenewStatus() === (bool)PendingRenewalInfo::AUTO_RENEW_ACTIVE) {
                    return [false, $this->translator->translate('apple_appstore.data_provider.delete.active_recurrent')];
                }
            }
        }
        return [true, null];
    }
}
