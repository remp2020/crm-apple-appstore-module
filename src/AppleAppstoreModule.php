<?php

namespace Crm\AppleAppstoreModule;

use Crm\ApiModule\Models\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Models\Authorization\NoAuthorization;
use Crm\ApiModule\Models\Router\ApiIdentifier;
use Crm\ApiModule\Models\Router\ApiRoute;
use Crm\AppleAppstoreModule\Api\ServerToServerNotificationV2WebhookApiHandler;
use Crm\AppleAppstoreModule\Api\ServerToServerNotificationWebhookApiHandler;
use Crm\AppleAppstoreModule\Api\VerifyPurchaseApiHandler;
use Crm\AppleAppstoreModule\Api\VerifyPurchaseV2ApiHandler;
use Crm\AppleAppstoreModule\Components\StopRecurrentPaymentInfoWidget\StopRecurrentPaymentInfoWidget;
use Crm\AppleAppstoreModule\DataProviders\AccessTokenDataProvider;
use Crm\AppleAppstoreModule\DataProviders\ExternalIdAdminFilterFormDataProvider;
use Crm\AppleAppstoreModule\DataProviders\ExternalIdUniversalSearchDataProvider;
use Crm\AppleAppstoreModule\Events\PairDeviceAccessTokensEventHandler;
use Crm\AppleAppstoreModule\Events\RemovedAccessTokenEventHandler;
use Crm\AppleAppstoreModule\Hermes\ServerToServerNotificationV2WebhookHandler;
use Crm\AppleAppstoreModule\Hermes\ServerToServerNotificationWebhookHandler;
use Crm\AppleAppstoreModule\Models\User\AppleAppstoreUserDataProvider;
use Crm\AppleAppstoreModule\Seeders\ConfigsSeeder;
use Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder;
use Crm\AppleAppstoreModule\Seeders\SnippetsSeeder;
use Crm\ApplicationModule\Application\Managers\SeederManager;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\ApplicationModule\Models\User\UserDataRegistrator;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManagerInterface;
use Crm\UsersModule\Events\PairDeviceAccessTokensEvent;
use Crm\UsersModule\Events\RemovedAccessTokenEvent;
use Crm\UsersModule\Models\Auth\UserTokenAuthorization;
use Tomaj\Hermes\Dispatcher;

class AppleAppstoreModule extends CrmModule
{
    public const META_KEY_ORIGINAL_TRANSACTION_ID = 'apple_appstore_original_transaction_id';
    public const META_KEY_PRODUCT_ID = 'apple_appstore_product_id';
    public const META_KEY_TRANSACTION_ID = 'apple_appstore_transaction_id';
    public const META_KEY_CANCELLATION_DATE = 'apple_appstore_cancellation_date';
    public const META_KEY_CANCELLATION_REASON = 'apple_appstore_cancellation_reason';

    public const USER_SOURCE_APP = 'ios-app';

    public function registerApiCalls(ApiRoutersContainerInterface $apiRoutersContainer)
    {
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'apple-appstore', 'webhook'),
                ServerToServerNotificationWebhookApiHandler::class,
                NoAuthorization::class
            )
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('2', 'apple-appstore', 'webhook'),
                ServerToServerNotificationV2WebhookApiHandler::class,
                NoAuthorization::class
            )
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'apple-appstore', 'verify-purchase'),
                VerifyPurchaseApiHandler::class,
                UserTokenAuthorization::class
            )
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('2', 'apple-appstore', 'verify-purchase'),
                VerifyPurchaseV2ApiHandler::class,
                UserTokenAuthorization::class
            )
        );
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(ConfigsSeeder::class));
        $seederManager->addSeeder($this->getInstance(PaymentGatewaysSeeder::class));
        $seederManager->addSeeder($this->getInstance(SnippetsSeeder::class), 100);
    }

    public function registerLazyEventHandlers(LazyEventEmitter $emitter)
    {
        $emitter->addListener(
            RemovedAccessTokenEvent::class,
            RemovedAccessTokenEventHandler::class
        );
        $emitter->addListener(
            PairDeviceAccessTokensEvent::class,
            PairDeviceAccessTokensEventHandler::class
        );
    }

    public function registerHermesHandlers(Dispatcher $dispatcher)
    {
        $dispatcher->registerHandler(
            'apple-server-to-server-notification',
            $this->getInstance(ServerToServerNotificationWebhookHandler::class)
        );
        $dispatcher->registerHandler(
            'apple-server-to-server-notification-v2',
            $this->getInstance(ServerToServerNotificationV2WebhookHandler::class)
        );
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.access_tokens',
            $this->getInstance(AccessTokenDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'payments.dataprovider.payments_filter_form',
            $this->getInstance(ExternalIdAdminFilterFormDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'admin.dataprovider.universal_search',
            $this->getInstance(ExternalIdUniversalSearchDataProvider::class)
        );
    }

    public function registerLazyWidgets(LazyWidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'frontend.payments.listing.recurrent',
            StopRecurrentPaymentInfoWidget::class,
            100
        );
        $widgetManager->registerWidget(
            'payments.user_payments.listing.recurrent',
            StopRecurrentPaymentInfoWidget::class,
            100
        );
    }

    public function registerUserData(UserDataRegistrator $dataRegistrator)
    {
        $dataRegistrator->addUserDataProvider($this->getInstance(AppleAppstoreUserDataProvider::class));
    }
}
