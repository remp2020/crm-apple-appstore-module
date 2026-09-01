<?php declare(strict_types=1);

namespace Crm\AppleAppstoreModule\Models\Offer;

use Crm\ApplicationModule\Helpers\EnumHelper;

/**
 * Values of the `offerDiscountType` field of Apple's transaction and renewal info.
 *
 * @see https://developer.apple.com/documentation/appstoreserverapi/offerdiscounttype
 */
enum OfferDiscountTypeEnum: string
{
    use EnumHelper;

    case FreeTrial = 'FREE_TRIAL';
    case PayAsYouGo = 'PAY_AS_YOU_GO';
    case PayUpFront = 'PAY_UP_FRONT';

    public static function fromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
