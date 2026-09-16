<?php

namespace Crm\AppleAppstoreModule\Models\UserResolver;

use Nette\Database\Table\ActiveRow;
use Readdle\AppStoreServerAPI\TransactionInfo;

interface UserResolverInterface
{
    /**
     * resolve returns the User matched with Apple's TransactionInfo.
     *
     * If no user is matched, an anonymous unclaimed user has to be returned. iOS in-app purchases
     * have to be possible without an account in CRM.
     *
     * @throws \Exception
     */
    public function resolve(TransactionInfo $transactionInfo): ActiveRow;
}
