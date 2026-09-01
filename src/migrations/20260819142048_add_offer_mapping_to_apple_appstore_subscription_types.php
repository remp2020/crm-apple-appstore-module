<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddOfferMappingToAppleAppstoreSubscriptionTypes extends AbstractMigration
{
    public function up(): void
    {
        // Apple allows only one introductory offer per product per territory group and reports it with
        // `offerIdentifier` set to NULL, so (offerDiscountType, offerPeriod) is the finest distinction the
        // App Store Server API gives us. Values are stored verbatim as Apple sends them.
        $this->table('apple_appstore_subscription_types')
            ->addColumn('offer_discount_type', 'string', [
                'null' => true,
                'after' => 'product_id',
                'comment' => 'Apple AppStore`s offerDiscountType (FREE_TRIAL, PAY_AS_YOU_GO, PAY_UP_FRONT); NULL for the base product pairing.',
            ])
            ->addColumn('offer_period', 'string', [
                'null' => true,
                'after' => 'offer_discount_type',
                'comment' => 'Apple AppStore`s offerPeriod (ISO-8601 duration, eg. P1M); NULL when the offer is not period-specific.',
            ])
            ->update();

        // Preserves the semantics of the removed `$followNextSubscriptionType` flag of
        // AppleAppstoreSubscriptionTypesRepository::findSubscriptionTypeByAppleAppstoreProductId(): the
        // paired row used to be the trial type and the regular one was reached through
        // subscription_types.next_subscription_type_id. Order matters, step 1 reads the pre-update value.
        $this->execute(
            "INSERT INTO `apple_appstore_subscription_types`
                (`product_id`, `offer_discount_type`, `offer_period`, `subscription_type_id`, `created_at`, `updated_at`)
             SELECT `apple`.`product_id`, 'FREE_TRIAL', NULL, `apple`.`subscription_type_id`, NOW(), NOW()
             FROM `apple_appstore_subscription_types` `apple`
             JOIN `subscription_types` `st` ON `st`.`id` = `apple`.`subscription_type_id`
             WHERE `apple`.`offer_discount_type` IS NULL
               AND `apple`.`offer_period` IS NULL
               AND `st`.`next_subscription_type_id` IS NOT NULL",
        );

        $this->execute(
            "UPDATE `apple_appstore_subscription_types` `apple`
             JOIN `subscription_types` `st` ON `st`.`id` = `apple`.`subscription_type_id`
             SET `apple`.`subscription_type_id` = `st`.`next_subscription_type_id`, `apple`.`updated_at` = NOW()
             WHERE `apple`.`offer_discount_type` IS NULL
               AND `apple`.`offer_period` IS NULL
               AND `st`.`next_subscription_type_id` IS NOT NULL",
        );

        $this->table('apple_appstore_subscription_types')
            ->removeIndex(['product_id'])
            ->addIndex(['product_id', 'offer_discount_type', 'offer_period'], ['unique' => true])
            ->update();
    }

    public function down(): void
    {
        $this->output->writeln('Down migration only restores the schema. Base pairings re-pointed to next_subscription_type_id are not restored.');

        // Offer rows have to go first, otherwise the unique index on product_id alone fails on duplicates.
        $this->execute(
            'DELETE FROM `apple_appstore_subscription_types`
             WHERE `offer_discount_type` IS NOT NULL OR `offer_period` IS NOT NULL',
        );

        $this->table('apple_appstore_subscription_types')
            ->removeIndex(['product_id', 'offer_discount_type', 'offer_period'])
            ->addIndex(['product_id'], ['unique' => true])
            ->update();

        $this->table('apple_appstore_subscription_types')
            ->removeColumn('offer_period')
            ->removeColumn('offer_discount_type')
            ->update();
    }
}
