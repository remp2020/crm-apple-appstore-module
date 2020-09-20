<?php

namespace Crm\AppleAppstoreModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class AppleAppstoreReceiptsRepository extends Repository
{
    protected $tableName = 'apple_appstore_receipts';

    final public function add(string $originalTransactionId, string $receipt)
    {
        $row = $this->findByOriginalTransactionId($originalTransactionId);
        if ($row) {
            $this->update($row, [
                'receipt' => $receipt,
            ]);
            return $row;
        }

        $now = new DateTime();
        return $this->getTable()->insert([
            'original_transaction_id' => $originalTransactionId,
            'receipt' => $receipt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    final public function findByOriginalTransactionId(string $originalTransactionId)
    {
        return $this->getTable()->where(['original_transaction_id' => $originalTransactionId])->fetch();
    }

    final public function update(IRow &$row, $data)
    {
        $data['updated_at'] = new DateTime();
        return parent::update($row, $data);
    }
}
