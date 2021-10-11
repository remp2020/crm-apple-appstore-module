<?php

namespace Crm\AppleAppstoreModule\Repository;

use Crm\ApplicationModule\Repository;
use Crm\ApplicationModule\Repository\AuditLogRepository;
use Nette\Caching\Storage;
use Nette\Database\Context;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class AppleAppstoreOriginalTransactionsRepository extends Repository
{
    protected $tableName = 'apple_appstore_original_transactions';

    public function __construct(
        AuditLogRepository $auditLogRepository,
        Context $database,
        Storage $cacheStorage = null
    ) {
        parent::__construct($database, $cacheStorage);
        $this->auditLogRepository = $auditLogRepository;
    }

    final public function add(string $originalTransactionId, string $receipt)
    {
        $row = $this->findByOriginalTransactionId($originalTransactionId);
        if ($row) {
            $this->update($row, [
                'latest_receipt' => $receipt,
            ]);
            return $row;
        }

        $now = new DateTime();
        return $this->getTable()->insert([
            'original_transaction_id' => $originalTransactionId,
            'latest_receipt' => $receipt,
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
