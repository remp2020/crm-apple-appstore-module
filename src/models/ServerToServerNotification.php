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
    /** Subscription was manually renewed by user after expiration */
    const NOTIFICATION_TYPE_INTERACTIVE_RENEWAL = "INTERACTIVE_RENEWAL";
    /** Customer made a change in their subscription plan that takes effect at the next renewal. The currently active plan is not affected */
    const NOTIFICATION_TYPE_DID_CHANGE_RENEWAL_PREF = "DID_CHANGE_RENEWAL_PREF";
    /** Customer changed subscription renewal status */
    const NOTIFICATION_TYPE_DID_CHANGE_RENEWAL_STATUS = "DID_CHANGE_RENEWAL_STATUS";

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

    public function getAutoRenewStatus(): bool
    {
        return (bool) $this->serverToServerNotification->auto_renew_status;
    }
}
