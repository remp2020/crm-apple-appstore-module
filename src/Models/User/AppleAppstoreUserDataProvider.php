<?php

namespace Crm\AppleAppstoreModule\Models\User;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Models\AppStoreServerApiFactory;
use Crm\AppleAppstoreModule\Models\Config;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreOriginalTransactionsRepository;
use Crm\ApplicationModule\Models\User\UserDataProviderInterface;
use Crm\ApplicationModule\Repositories\ConfigsRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Exception;
use Nette\Localization\Translator;
use Readdle\AppStoreServerAPI\Exception\AppStoreServerAPIException;

class AppleAppstoreUserDataProvider implements UserDataProviderInterface
{

    public function __construct(
        private readonly AppleAppstoreOriginalTransactionsRepository $appleAppstoreOriginalTransactionsRepository,
        private readonly ConfigsRepository $configsRepository,
        private readonly Translator $translator,
        private readonly SubscriptionsRepository $subscriptionsRepository,
        private readonly SubscriptionMetaRepository $subscriptionMetaRepository,
        private readonly PaymentsRepository $paymentsRepository,
        private readonly PaymentMetaRepository $paymentMetaRepository,
        private readonly AppStoreServerApiFactory $appStoreServerApiFactory,
    ) {
    }

    public static function identifier(): string
    {
        return 'apple_appstore';
    }

    public function data($userId): ?array
    {
        return null;
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
        $metaKeys = [
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            AppleAppstoreModule::META_KEY_PRODUCT_ID,
            AppleAppstoreModule::META_KEY_CANCELLATION_DATE,
            AppleAppstoreModule::META_KEY_CANCELLATION_REASON,
        ];
        $userPayments = $this->paymentsRepository->userPayments($userId);
        if ($userPayments) {
            foreach ($userPayments as $userPayment) {
                foreach ($metaKeys as $key => $value) {
                    $row = $this->paymentMetaRepository->findByPaymentAndKey($userPayment, $value);
                    if ($row) {
                        $this->paymentMetaRepository->delete($row);
                    }
                }
            }
        }

        $userSubscriptions = $this->subscriptionsRepository->userSubscriptions($userId);
        if ($userSubscriptions) {
            foreach ($userSubscriptions as $userSubscription) {
                foreach ($metaKeys as $key => $value) {
                    $row = $this->subscriptionMetaRepository->findBySubscriptionAndKey($userSubscription, $value);
                    if ($row) {
                        $this->subscriptionMetaRepository->delete($row);
                    }
                }
            }
        }
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
        $userPayments = $this->paymentsRepository
            ->userPayments($userId)
            ->where([
                'status' => PaymentsRepository::STATUS_PREPAID,
                'payment_gateway.code' => AppleAppstoreGateway::GATEWAY_CODE,
            ]);
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
            $appStoreServerApi = $this->appStoreServerApiFactory->create();
            try {
                // https://developer.apple.com/documentation/appstoreserverapi/get_all_subscription_statuses
                $transactionStatuses = $appStoreServerApi->getAllSubscriptionStatuses(
                    transactionId: $originalTransactionRow->original_transaction_id,
                    queryParams:  ['status' => ['1', '3', '4']]
                );
            } catch (AppStoreServerAPIException $e) {
                throw new \Exception("Unable to validate Apple AppStore payment. Error: [{$e->getMessage()}]");
            }

            if (count($transactionStatuses->getData()) > 0) {
                return [false, $this->translator->translate('apple_appstore.data_provider.delete.active_recurrent')];
            }
        }
        return [true, null];
    }
}
