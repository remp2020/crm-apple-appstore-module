<?php

namespace Crm\AppleAppstoreModule\Model;

use Crm\ApplicationModule\Config\ApplicationConfig;
use ReceiptValidator\iTunes\Validator;

class AppleAppstoreValidatorFactory
{
    private $applicationConfig;

    public function __construct(ApplicationConfig $applicationConfig)
    {
        $this->applicationConfig = $applicationConfig;
    }

    public function create(): Validator
    {
        $sharedSecret = $this->applicationConfig->get(Config::SHARED_SECRET);
        if (!$sharedSecret) {
            throw new \Exception('Missing application configuration [' . Config::SHARED_SECRET . '].');
        }

        $gatewayMode = $this->applicationConfig->get(Config::GATEWAY_MODE);
        if ($gatewayMode === 'live') {
            $endpoint = Validator::ENDPOINT_PRODUCTION;
        } else {
            $endpoint = Validator::ENDPOINT_SANDBOX;
        }

        $client = new Validator($endpoint);
        $client->setSharedSecret($sharedSecret);

        return $client;
    }
}
