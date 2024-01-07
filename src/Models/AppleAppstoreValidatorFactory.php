<?php

namespace Crm\AppleAppstoreModule\Models;

use Crm\ApplicationModule\Config\ApplicationConfig;
use ReceiptValidator\iTunes\Validator;

class AppleAppstoreValidatorFactory
{
    public const GATEWAY_MODE_LIVE = 'live';
    public const GATEWAY_MODE_SANDBOX = 'sandbox';

    private ApplicationConfig $applicationConfig;

    public function __construct(ApplicationConfig $applicationConfig)
    {
        $this->applicationConfig = $applicationConfig;
    }

    public function create(?string $gatewayMode = null): Validator
    {
        $sharedSecret = $this->applicationConfig->get(Config::SHARED_SECRET);
        if (!$sharedSecret) {
            throw new \Exception('Missing application configuration [' . Config::SHARED_SECRET . '].');
        }

        if (!$gatewayMode) {
            $gatewayMode = $this->applicationConfig->get(Config::GATEWAY_MODE);
        }

        if ($gatewayMode === self::GATEWAY_MODE_LIVE) {
            $endpoint = Validator::ENDPOINT_PRODUCTION;
        } else {
            $endpoint = Validator::ENDPOINT_SANDBOX;
        }

        $client = new Validator($endpoint);
        $client->setSharedSecret($sharedSecret);

        return $client;
    }
}
