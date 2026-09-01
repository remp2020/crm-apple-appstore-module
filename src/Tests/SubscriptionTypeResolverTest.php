<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\Models\Offer\OfferDiscountTypeEnum;
use Crm\AppleAppstoreModule\Models\Offer\OfferTypeEnum;
use Crm\AppleAppstoreModule\Models\SubscriptionTypeResolver\SubscriptionTypeResolverInterface;
use Crm\AppleAppstoreModule\Models\SubscriptionTypeResolver\TransactionProductOfferNotMatchedException;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Models\Extension\ExtendSameContentAccess;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeNamesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Nette\Database\Table\ActiveRow;
use PHPUnit\Framework\Attributes\DataProvider;
use Readdle\AppStoreServerAPI\RenewalInfo;
use Readdle\AppStoreServerAPI\TransactionInfo;

class SubscriptionTypeResolverTest extends DatabaseTestCase
{
    private const PRODUCT_ID = 'apple_appstore_test_product_id';
    private const BASE_SUBSCRIPTION_TYPE_CODE = 'apple_appstore_test_base';
    private const INTRO_SUBSCRIPTION_TYPE_CODE = 'apple_appstore_test_intro';
    private const DOWNGRADED_PRODUCT_ID = 'apple_appstore_test_downgraded_product_id';
    private const DOWNGRADED_SUBSCRIPTION_TYPE_CODE = 'apple_appstore_test_downgraded';

    private SubscriptionTypeResolverInterface $subscriptionTypeResolver;
    private AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository;
    private SubscriptionTypeBuilder $subscriptionTypeBuilder;

    protected function requiredRepositories(): array
    {
        return [
            AppleAppstoreSubscriptionTypesRepository::class,

            SubscriptionTypesRepository::class,
            SubscriptionTypeBuilder::class,
            SubscriptionTypeItemsRepository::class,
            SubscriptionTypeNamesRepository::class,
        ];
    }

    public function requiredSeeders(): array
    {
        return [
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
            SubscriptionTypeNamesSeeder::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriptionTypeResolver = $this->inject(SubscriptionTypeResolverInterface::class);
        $this->appleAppstoreSubscriptionTypesRepository = $this->getRepository(AppleAppstoreSubscriptionTypesRepository::class);
        $this->subscriptionTypeBuilder = $this->getRepository(SubscriptionTypeBuilder::class);
    }

    public function testResolveWithoutOfferReturnsBaseSubscriptionType(): void
    {
        $base = $this->createBaseSubscriptionType();
        $this->createIntroSubscriptionType($base);

        $subscriptionType = $this->subscriptionTypeResolver->resolve($this->transactionInfo());

        $this->assertSame(self::BASE_SUBSCRIPTION_TYPE_CODE, $subscriptionType->code);
    }

    public function testResolveIntroductoryOfferReturnsPairedSubscriptionType(): void
    {
        $base = $this->createBaseSubscriptionType();
        $intro = $this->createIntroSubscriptionType($base);
        $this->appleAppstoreSubscriptionTypesRepository->upsert(
            self::PRODUCT_ID,
            $intro,
            OfferDiscountTypeEnum::PayAsYouGo,
            'P1M',
        );

        $subscriptionType = $this->subscriptionTypeResolver->resolve($this->transactionInfo([
            'offerType' => OfferTypeEnum::Introductory->value,
            'offerDiscountType' => OfferDiscountTypeEnum::PayAsYouGo->value,
            'offerPeriod' => 'P1M',
        ]));

        $this->assertSame(self::INTRO_SUBSCRIPTION_TYPE_CODE, $subscriptionType->code);
    }

    public function testResolveIntroductoryOfferFallsBackToCoarseMapping(): void
    {
        $base = $this->createBaseSubscriptionType();
        $intro = $this->createIntroSubscriptionType($base);
        $this->appleAppstoreSubscriptionTypesRepository->upsert(
            self::PRODUCT_ID,
            $intro,
            OfferDiscountTypeEnum::PayAsYouGo,
        );

        $subscriptionType = $this->subscriptionTypeResolver->resolve($this->transactionInfo([
            'offerType' => OfferTypeEnum::Introductory->value,
            'offerDiscountType' => OfferDiscountTypeEnum::PayAsYouGo->value,
            'offerPeriod' => 'P2M',
        ]));

        $this->assertSame(self::INTRO_SUBSCRIPTION_TYPE_CODE, $subscriptionType->code);
    }

    public function testResolveUnmappedIntroductoryOfferThrows(): void
    {
        $base = $this->createBaseSubscriptionType();
        $this->createIntroSubscriptionType($base);

        $this->expectException(TransactionProductOfferNotMatchedException::class);
        $this->subscriptionTypeResolver->resolve($this->transactionInfo([
            'offerType' => OfferTypeEnum::Introductory->value,
            'offerDiscountType' => OfferDiscountTypeEnum::PayUpFront->value,
            'offerPeriod' => 'P1Y',
        ]));
    }

    public function testResolveIntroductoryOfferWithoutDiscountTypeThrows(): void
    {
        $base = $this->createBaseSubscriptionType();
        $intro = $this->createIntroSubscriptionType($base);
        $this->appleAppstoreSubscriptionTypesRepository->upsert(
            self::PRODUCT_ID,
            $intro,
            OfferDiscountTypeEnum::PayAsYouGo,
            'P1M',
        );

        $this->expectException(TransactionProductOfferNotMatchedException::class);
        $this->subscriptionTypeResolver->resolve($this->transactionInfo([
            'offerType' => OfferTypeEnum::Introductory->value,
        ]));
    }

    public static function offerTypesDataProvider(): array
    {
        return [
            'introductory' => [OfferTypeEnum::Introductory],
            'promotional' => [OfferTypeEnum::Promotional],
            'subscriptionOfferCode' => [OfferTypeEnum::SubscriptionOfferCode],
            'winBack' => [OfferTypeEnum::WinBack],
        ];
    }

    #[DataProvider('offerTypesDataProvider')]
    public function testResolveUnpairedOfferThrows(OfferTypeEnum $offerType): void
    {
        $this->createBaseSubscriptionType();

        $this->expectException(TransactionProductOfferNotMatchedException::class);
        $this->subscriptionTypeResolver->resolve($this->transactionInfo([
            'offerType' => $offerType->value,
            'offerDiscountType' => OfferDiscountTypeEnum::PayAsYouGo->value,
            'offerPeriod' => 'P1M',
        ]));
    }

    #[DataProvider('offerTypesDataProvider')]
    public function testResolvePairedOfferReturnsPairedSubscriptionType(OfferTypeEnum $offerType): void
    {
        $base = $this->createBaseSubscriptionType();
        $intro = $this->createIntroSubscriptionType($base);
        $this->appleAppstoreSubscriptionTypesRepository->upsert(
            self::PRODUCT_ID,
            $intro,
            OfferDiscountTypeEnum::PayAsYouGo,
            'P1M',
        );

        $subscriptionType = $this->subscriptionTypeResolver->resolve($this->transactionInfo([
            'offerType' => $offerType->value,
            'offerDiscountType' => OfferDiscountTypeEnum::PayAsYouGo->value,
            'offerPeriod' => 'P1M',
        ]));

        $this->assertSame(self::INTRO_SUBSCRIPTION_TYPE_CODE, $subscriptionType->code);
    }

    public function testResolveUnknownProductThrows(): void
    {
        $this->expectException(TransactionProductOfferNotMatchedException::class);
        $this->expectExceptionMessage('Unable to find SubscriptionType for product ID');
        $this->subscriptionTypeResolver->resolve($this->transactionInfo());
    }

    public function testResolveDoesNotFollowNextSubscriptionType(): void
    {
        $regular = $this->createBaseSubscriptionType();
        $intro = $this->createIntroSubscriptionType($regular);
        $this->appleAppstoreSubscriptionTypesRepository->upsert(self::PRODUCT_ID, $intro);

        $subscriptionType = $this->subscriptionTypeResolver->resolve($this->transactionInfo());

        $this->assertSame(self::INTRO_SUBSCRIPTION_TYPE_CODE, $subscriptionType->code);
        $this->assertNotNull($subscriptionType->next_subscription_type_id);
    }

    public function testResolveForRenewalWithoutOfferReturnsBaseSubscriptionType(): void
    {
        $base = $this->createBaseSubscriptionType();
        $this->createIntroSubscriptionType($base);

        $subscriptionType = $this->subscriptionTypeResolver->resolveForRenewal($this->renewalInfo());

        $this->assertSame(self::BASE_SUBSCRIPTION_TYPE_CODE, $subscriptionType->code);
    }

    public function testResolveForRenewalIntroductoryOfferReturnsPairedSubscriptionType(): void
    {
        $base = $this->createBaseSubscriptionType();
        $intro = $this->createIntroSubscriptionType($base);
        $this->appleAppstoreSubscriptionTypesRepository->upsert(
            self::PRODUCT_ID,
            $intro,
            OfferDiscountTypeEnum::PayAsYouGo,
            'P1M',
        );

        $subscriptionType = $this->subscriptionTypeResolver->resolveForRenewal($this->renewalInfo([
            'offerType' => OfferTypeEnum::Introductory->value,
            'offerDiscountType' => OfferDiscountTypeEnum::PayAsYouGo->value,
            'offerPeriod' => 'P1M',
        ]));

        $this->assertSame(self::INTRO_SUBSCRIPTION_TYPE_CODE, $subscriptionType->code);
    }

    /**
     * RenewalInfo describes the upcoming renewal, so the pairing has to be resolved by `autoRenewProductId`;
     * `productId` still holds the product of the subscription which is running now.
     */
    public function testResolveForRenewalUsesAutoRenewProductId(): void
    {
        $this->createBaseSubscriptionType();
        $downgradedTo = $this->subscriptionTypeBuilder->createNew()
            ->setNameAndUserLabel('apple appstore test downgraded subscription month')
            ->setCode(self::DOWNGRADED_SUBSCRIPTION_TYPE_CODE)
            ->setPrice(4.99)
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();
        $this->appleAppstoreSubscriptionTypesRepository->upsert(self::DOWNGRADED_PRODUCT_ID, $downgradedTo);

        $subscriptionType = $this->subscriptionTypeResolver->resolveForRenewal($this->renewalInfo([
            'productId' => self::PRODUCT_ID,
            'autoRenewProductId' => self::DOWNGRADED_PRODUCT_ID,
        ]));

        $this->assertSame(self::DOWNGRADED_SUBSCRIPTION_TYPE_CODE, $subscriptionType->code);
    }

    public static function renewalInfoUnpairedOffersDataProvider(): array
    {
        return [
            'unmappedIntroductoryOffer' => [[
                'offerType' => OfferTypeEnum::Introductory->value,
                'offerDiscountType' => OfferDiscountTypeEnum::PayUpFront->value,
                'offerPeriod' => 'P1Y',
            ]],
            'promotional' => [['offerType' => OfferTypeEnum::Promotional->value, 'offerIdentifier' => 'test']],
            'subscriptionOfferCode' => [['offerType' => OfferTypeEnum::SubscriptionOfferCode->value, 'offerIdentifier' => 'test']],
            'winBack' => [['offerType' => OfferTypeEnum::WinBack->value, 'offerIdentifier' => 'test']],
        ];
    }

    #[DataProvider('renewalInfoUnpairedOffersDataProvider')]
    public function testResolveForRenewalUnpairedOfferThrows(array $offer): void
    {
        $base = $this->createBaseSubscriptionType();
        $intro = $this->createIntroSubscriptionType($base);
        $this->appleAppstoreSubscriptionTypesRepository->upsert(
            self::PRODUCT_ID,
            $intro,
            OfferDiscountTypeEnum::PayAsYouGo,
            'P1M',
        );

        $this->expectException(TransactionProductOfferNotMatchedException::class);
        $this->subscriptionTypeResolver->resolveForRenewal($this->renewalInfo($offer));
    }

    public function testResolveForRenewalUnknownProductThrows(): void
    {
        $this->expectException(TransactionProductOfferNotMatchedException::class);
        $this->subscriptionTypeResolver->resolveForRenewal($this->renewalInfo());
    }

    private function transactionInfo(array $offer = []): TransactionInfo
    {
        return TransactionInfo::createFromRawTransactionInfo(array_merge([
            'productId' => self::PRODUCT_ID,
            'transactionId' => '300002150129622',
            'originalTransactionId' => 'test_original_transaction_id_value',
        ], $offer));
    }

    private function renewalInfo(array $offer = []): RenewalInfo
    {
        return RenewalInfo::createFromRawRenewalInfo(array_merge([
            'autoRenewProductId' => self::PRODUCT_ID,
            'transactionId' => '300002150129623',
            'originalTransactionId' => 'test_original_transaction_id_value',
        ], $offer));
    }

    private function createBaseSubscriptionType(): ActiveRow
    {
        $subscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setNameAndUserLabel('apple appstore test base subscription month')
            ->setCode(self::BASE_SUBSCRIPTION_TYPE_CODE)
            ->setPrice(8.99)
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();

        $this->appleAppstoreSubscriptionTypesRepository->upsert(self::PRODUCT_ID, $subscriptionType);

        return $subscriptionType;
    }

    private function createIntroSubscriptionType(ActiveRow $nextSubscriptionType): ActiveRow
    {
        return $this->subscriptionTypeBuilder->createNew()
            ->setNameAndUserLabel('apple appstore test intro subscription month')
            ->setCode(self::INTRO_SUBSCRIPTION_TYPE_CODE)
            ->setPrice(0.99)
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->setTrialPeriods(1)
            ->setNextSubscriptionTypeId($nextSubscriptionType->id)
            ->save();
    }
}
