<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\Models\Offer\OfferDiscountTypeEnum;
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

class AppleAppstoreSubscriptionTypesRepositoryTest extends DatabaseTestCase
{
    private const PRODUCT_ID = 'apple_appstore_test_product_id';

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

        $this->appleAppstoreSubscriptionTypesRepository = $this->getRepository(AppleAppstoreSubscriptionTypesRepository::class);
        $this->subscriptionTypeBuilder = $this->getRepository(SubscriptionTypeBuilder::class);
    }

    public function testAddStoresNullsForMissingOfferValues(): void
    {
        $row = $this->appleAppstoreSubscriptionTypesRepository->add(
            self::PRODUCT_ID,
            $this->createSubscriptionType('base'),
        );

        $this->assertNull($row->offer_discount_type);
        $this->assertNull($row->offer_period);
        $this->assertNotNull($this->appleAppstoreSubscriptionTypesRepository->findSubscriptionTypeMapping(self::PRODUCT_ID));
    }

    public function testUpsertKeysOnAllOfferColumns(): void
    {
        $base = $this->createSubscriptionType('base');
        $intro = $this->createSubscriptionType('intro');
        $updatedBase = $this->createSubscriptionType('updated_base');

        $baseRow = $this->appleAppstoreSubscriptionTypesRepository->upsert(self::PRODUCT_ID, $base);
        $introRow = $this->appleAppstoreSubscriptionTypesRepository->upsert(
            self::PRODUCT_ID,
            $intro,
            OfferDiscountTypeEnum::PayAsYouGo,
            'P1M',
        );
        $this->assertNotSame($baseRow->id, $introRow->id);

        $this->appleAppstoreSubscriptionTypesRepository->upsert(self::PRODUCT_ID, $updatedBase);

        $this->assertCount(2, $this->appleAppstoreSubscriptionTypesRepository->getTable()->fetchAll());
        $this->assertSame(
            $updatedBase->id,
            $this->appleAppstoreSubscriptionTypesRepository->findSubscriptionType(self::PRODUCT_ID)->id,
        );
        $this->assertSame(
            $intro->id,
            $this->appleAppstoreSubscriptionTypesRepository->findOfferSubscriptionType(
                self::PRODUCT_ID,
                OfferDiscountTypeEnum::PayAsYouGo,
                'P1M',
            )->id,
        );
    }

    public function testFindSubscriptionTypeIgnoresOfferRows(): void
    {
        $intro = $this->createSubscriptionType('intro');
        $this->appleAppstoreSubscriptionTypesRepository->add(
            self::PRODUCT_ID,
            $intro,
            OfferDiscountTypeEnum::PayAsYouGo,
            'P1M',
        );

        $this->assertNull($this->appleAppstoreSubscriptionTypesRepository->findSubscriptionType(self::PRODUCT_ID));
    }

    public function testFindOfferSubscriptionTypeFallsBackFromExactToCoarseMapping(): void
    {
        $exact = $this->createSubscriptionType('exact');
        $coarse = $this->createSubscriptionType('coarse');

        $this->appleAppstoreSubscriptionTypesRepository->add(
            self::PRODUCT_ID,
            $coarse,
            OfferDiscountTypeEnum::PayAsYouGo,
        );
        $this->assertSame(
            $coarse->id,
            $this->appleAppstoreSubscriptionTypesRepository->findOfferSubscriptionType(
                self::PRODUCT_ID,
                OfferDiscountTypeEnum::PayAsYouGo,
                'P1M',
            )->id,
        );

        $this->appleAppstoreSubscriptionTypesRepository->add(
            self::PRODUCT_ID,
            $exact,
            OfferDiscountTypeEnum::PayAsYouGo,
            'P1M',
        );
        $this->assertSame(
            $exact->id,
            $this->appleAppstoreSubscriptionTypesRepository->findOfferSubscriptionType(
                self::PRODUCT_ID,
                OfferDiscountTypeEnum::PayAsYouGo,
                'P1M',
            )->id,
        );
    }

    public function testFindOfferSubscriptionTypeReturnsNullWithoutDiscountType(): void
    {
        $this->appleAppstoreSubscriptionTypesRepository->add(self::PRODUCT_ID, $this->createSubscriptionType('base'));

        $this->assertNull($this->appleAppstoreSubscriptionTypesRepository->findOfferSubscriptionType(
            self::PRODUCT_ID,
            null,
            'P1M',
        ));
    }

    public function testFindOfferSubscriptionTypeReturnsNullForUnmappedDiscountType(): void
    {
        $this->appleAppstoreSubscriptionTypesRepository->add(self::PRODUCT_ID, $this->createSubscriptionType('base'));

        $this->assertNull($this->appleAppstoreSubscriptionTypesRepository->findOfferSubscriptionType(
            self::PRODUCT_ID,
            OfferDiscountTypeEnum::FreeTrial,
            'P1M',
        ));
    }

    /**
     * The deprecated legacy Apple App Store API v1 lookup has to keep returning what it returned before the
     * offer columns were introduced: the base row when following the chain, the trial row otherwise.
     */
    public function testDeprecatedFindSubscriptionTypeByAppleAppstoreProductId(): void
    {
        $regular = $this->createSubscriptionType('regular');
        $trial = $this->createSubscriptionType('trial', $regular);

        $this->appleAppstoreSubscriptionTypesRepository->add(self::PRODUCT_ID, $regular);
        $this->appleAppstoreSubscriptionTypesRepository->add(
            self::PRODUCT_ID,
            $trial,
            OfferDiscountTypeEnum::FreeTrial,
        );

        $this->assertSame(
            $regular->id,
            $this->appleAppstoreSubscriptionTypesRepository
                ->findSubscriptionTypeByAppleAppstoreProductId(self::PRODUCT_ID)->id,
        );
        $this->assertSame(
            $trial->id,
            $this->appleAppstoreSubscriptionTypesRepository
                ->findSubscriptionTypeByAppleAppstoreProductId(self::PRODUCT_ID, false)->id,
        );
    }

    public function testDeprecatedFindSubscriptionTypeByAppleAppstoreProductIdWithoutTrialRow(): void
    {
        $regular = $this->createSubscriptionType('regular');
        $this->appleAppstoreSubscriptionTypesRepository->add(self::PRODUCT_ID, $regular);

        $this->assertSame(
            $regular->id,
            $this->appleAppstoreSubscriptionTypesRepository
                ->findSubscriptionTypeByAppleAppstoreProductId(self::PRODUCT_ID, false)->id,
        );
    }

    private function createSubscriptionType(string $code, ?ActiveRow $nextSubscriptionType = null): ActiveRow
    {
        $builder = $this->subscriptionTypeBuilder->createNew()
            ->setNameAndUserLabel("apple appstore test {$code}")
            ->setCode("apple_appstore_test_{$code}")
            ->setPrice(8.99)
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE);

        if ($nextSubscriptionType) {
            $builder->setNextSubscriptionTypeId($nextSubscriptionType->id);
        }

        return $builder->save();
    }
}
