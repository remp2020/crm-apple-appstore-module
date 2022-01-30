<?php

namespace Crm\AppleAppstoreModule\Model;

class PendingRenewalInfo
{
    /** https://developer.apple.com/documentation/appstorereceipts/expiration_intent */
    public const EXPIRATION_INTENT_CANCELLED_SUBSCRIPTION = "1";
    public const EXPIRATION_INTENT_BILLING_ERROR = "2";
    public const EXPIRATION_INTENT_DISAGREE_PRICE_CHANGE = "3";
    public const EXPIRATION_INTENT_PRODUCT_NOT_AVAILABLE_AT_RENEWAL = "4";
    public const EXPIRATION_INTENT_UNKNOWN_ERROR = "5";

    protected $pendingRenewalInfo;

    public function __construct($pendingRenewalInfo)
    {
        $this->pendingRenewalInfo = $pendingRenewalInfo;
    }

    public function getProductId(): string
    {
        return $this->pendingRenewalInfo->product_id;
    }

    public function getAutoRenewProductId(): string
    {
        return $this->pendingRenewalInfo->auto_renew_product_id;
    }

    public function getOriginalTransactionId(): string
    {
        return $this->pendingRenewalInfo->original_transaction_id;
    }

    public function getAutoRenewStatus(): bool
    {
        return (bool) $this->pendingRenewalInfo->auto_renew_status;
    }

    public function getExpirationIntent(): ?string
    {
        return $this->pendingRenewalInfo->expiration_intent ?? null;
    }

    public function getGracePeriodExpiresDateMs(): ?string
    {
        return $this->pendingRenewalInfo->grace_period_expires_date_ms ?? null;
    }

    public function getIsInBillingRetryPeriod(): ?bool
    {
        if (isset($this->pendingRenewalInfo->is_in_billing_retry_period)) {
            return (bool) $this->pendingRenewalInfo->is_in_billing_retry_period;
        }
        return null;
    }
}
