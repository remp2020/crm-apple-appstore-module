<?php

namespace Crm\AppleAppstoreModule;

use Crm\ApiModule\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Router\ApiIdentifier;
use Crm\ApiModule\Router\ApiRoute;
use Crm\AppleAppstoreModule\Api\VerifyPurchaseApiHandler;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\SeederManager;
use Crm\ApplicationModule\User\UserDataRegistrator;
use Crm\ApplicationModule\Widget\LazyWidgetManagerInterface;
use Crm\UsersModule\Auth\UserTokenAuthorization;
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
                \Crm\AppleAppstoreModule\Api\ServerToServerNotificationWebhookApiHandler::class,
                \Crm\ApiModule\Authorization\NoAuthorization::class
            )
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'apple-appstore', 'verify-purchase'),
                VerifyPurchaseApiHandler::class,
                UserTokenAuthorization::class
            )
        );
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(\Crm\AppleAppstoreModule\Seeders\ConfigsSeeder::class));
        $seederManager->addSeeder($this->getInstance(\Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder::class));
        $seederManager->addSeeder($this->getInstance(\Crm\AppleAppstoreModule\Seeders\SnippetsSeeder::class), 100);
    }

    public function registerLazyEventHandlers(\Crm\ApplicationModule\Event\LazyEventEmitter $emitter)
    {
        $emitter->addListener(
            \Crm\UsersModule\Events\RemovedAccessTokenEvent::class,
            \Crm\AppleAppstoreModule\Events\RemovedAccessTokenEventHandler::class
        );
        $emitter->addListener(
            \Crm\UsersModule\Events\PairDeviceAccessTokensEvent::class,
            \Crm\AppleAppstoreModule\Events\PairDeviceAccessTokensEventHandler::class
        );
    }

    public function registerHermesHandlers(Dispatcher $dispatcher)
    {
        $dispatcher->registerHandler(
            'apple-server-to-server-notification',
            $this->getInstance(\Crm\AppleAppstoreModule\Hermes\ServerToServerNotificationWebhookHandler::class)
        );
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.access_tokens',
            $this->getInstance(\Crm\AppleAppstoreModule\DataProviders\AccessTokenDataProvider::class)
        );
    }

    public function registerLazyWidgets(LazyWidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'frontend.payments.listing.recurrent',
            \Crm\AppleAppstoreModule\Components\StopRecurrentPaymentInfoWidget::class,
            100
        );
        $widgetManager->registerWidget(
            'payments.user_payments.listing.recurrent',
            \Crm\AppleAppstoreModule\Components\StopRecurrentPaymentInfoWidget::class,
            100
        );
    }

    public function registerUserData(UserDataRegistrator $dataRegistrator)
    {
        $dataRegistrator->addUserDataProvider($this->getInstance(\Crm\AppleAppstoreModule\User\AppleAppstoreUserDataProvider::class));
    }
}
