<?php

namespace Crm\AppleAppstoreModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;

class AppleAppstoreTransactionDeviceTokensRepository extends Repository
{
    protected $tableName = 'apple_appstore_transaction_device_tokens';

    final public function add(ActiveRow $appleAppstoreOriginalTransaction, ActiveRow $deviceToken)
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
