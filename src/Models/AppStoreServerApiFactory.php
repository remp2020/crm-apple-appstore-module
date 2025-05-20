<?php

namespace Crm\AppleAppstoreModule\Models;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Readdle\AppStoreServerAPI\AppStoreServerAPI;
use Readdle\AppStoreServerAPI\Environment;
use Readdle\AppStoreServerAPI\Exception\WrongEnvironmentException;

class AppStoreServerApiFactory
{
    public function __construct(
        private readonly ApplicationConfig $applicationConfig,
    ) {
    }

    /**
     * @throws WrongEnvironmentException
     */
    public function create(?string $environment = null): AppStoreServerAPI
    {
        $issuerId = $this->applicationConfig->get(Config::ISSUER_ID);
        if (!$issuerId) {
            throw new \Exception('Missing application configuration [' . Config::ISSUER_ID . '].');
        }

        $bundleId = $this->applicationConfig->get(Config::BUNDLE_ID);
        if (!$bundleId) {
            throw new \Exception('Missing application configuration [' . Config::BUNDLE_ID . '].');
        }

        $key = $this->applicationConfig->get(Config::APP_STORE_SERVER_API_KEY);
        if (!$key) {
            throw new \Exception('Missing application configuration [' . Config::APP_STORE_SERVER_API_KEY . '].');
        }

        $keyId = $this->applicationConfig->get(Config::APP_STORE_SERVER_API_KEY_ID);
        if (!$keyId) {
            throw new \Exception('Missing application configuration [' . Config::APP_STORE_SERVER_API_KEY_ID . '].');
        }

        if (!$environment) {
            $environment = $this->applicationConfig->get(Config::GATEWAY_MODE);
        }

        if ($environment === Config::GATEWAY_MODE_LIVE) {
            $environment = Environment::PRODUCTION;
        } else {
            $environment = Environment::SANDBOX;
        }

        return new AppStoreServerAPI(
            $environment,
            $issuerId,
            $bundleId,
            $keyId,
            file_get_contents($key),
        );
    }
}
