<?php declare(strict_types=1);

namespace Crm\AppleAppstoreModule\Models\Offer;

use Crm\ApplicationModule\Helpers\EnumHelper;

/**
 * Values of the `offerType` field of Apple's transaction and renewal info.
 *
 * @see https://developer.apple.com/documentation/appstoreserverapi/offertype
 */
enum OfferTypeEnum: int
{
    use EnumHelper;

    case Introductory = 1;
    case Promotional = 2;
    case SubscriptionOfferCode = 3;
    case WinBack = 4;

    public static function fromValue(?int $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
