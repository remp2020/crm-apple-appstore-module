<?php

namespace Crm\AppleAppstoreModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;

class AppleAppstoreTransactionDeviceTokensRepository extends Repository
{
    protected $tableName = 'apple_appstore_transaction_device_tokens';

    final public function add(IRow $appleAppstoreOriginalTransaction, IRow $deviceToken)
    {
        $payload = [
            'original_transaction_id' => $appleAppstoreOriginalTransaction->id,
            'device_token_id' => $deviceToken->id,
        ];

        $row = $this->getTable()->where($payload)->fetch();
        if ($row) {
            return $row;
        }
        return $this->getTable()->insert($payload);
    }
}
