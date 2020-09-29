<?php

namespace Crm\AppleAppstoreModule\DataProviders;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\UsersModule\DataProvider\AccessTokenDataProviderInterface;
use Nette\Database\Table\IRow;

class AccessTokenDataProvider implements AccessTokenDataProviderInterface
{
    public function canUnpairDeviceToken(IRow $accessToken, IRow $deviceToken): bool
    {
        if ($accessToken->source === AppleAppstoreModule::USER_SOURCE_APP) {
            return false;
        }
        return true;
    }

    public function provide(array $params)
    {
        throw new \Exception('AccessTokenDataProvider does not provide generic method results');
    }
}
