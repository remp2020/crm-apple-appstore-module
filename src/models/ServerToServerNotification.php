<?php

namespace Crm\AppleAppstoreModule\Model;

class ServerToServerNotification
{
    /** First purchase of subscription */
    const NOTIFICATION_TYPE_INITIAL_BUY = "INITIAL_BUY";
    /** Cancelled by customer via helpdesk; or part of upgrade. */
    const NOTIFICATION_TYPE_CANCEL = "CANCEL";
    /** @deprecated Use NOTIFICATION_TYPE_DID_RECOVER */
    const NOTIFICATION_TYPE_RENEWAL = "RENEWAL";
    /** Expired subscription recovered by AppStore after billing issue through a billing retry */
    const NOTIFICATION_TYPE_DID_RECOVER = "DID_RECOVER";

    protected $serverToServerNotification;

    public function __construct($serverToServerNotification)
    {
        $this->serverToServerNotification = $serverToServerNotification;
    }

    public function getNotificationType(): string
    {
        return $this->serverToServerNotification->notification_type;
    }

    public function getUnifiedReceipt(): UnifiedReceipt
    {
        return new UnifiedReceipt($this->serverToServerNotification->unified_receipt);
    }
}
