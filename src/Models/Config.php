<?php

namespace Crm\AppleAppstoreModule\Models;

class Config
{
    public const GATEWAY_MODE = 'apple_appstore_gateway_mode';
    public const GATEWAY_MODE_LIVE = 'live';
    public const SHARED_SECRET = 'apple_appstore_shared_secret';
    public const APPLE_BLOCK_ANONYMIZATION = 'apple_appstore_block_anonymization';

    public const ISSUER_ID = 'apple_appstore_issuer_id';
    public const BUNDLE_ID = 'apple_appstore_bundle_id';
    public const APP_STORE_SERVER_API_KEY = 'apple_appstore_server_api_key';
    public const APP_STORE_SERVER_API_KEY_ID = 'apple_appstore_server_api_key_id';
    public const NOTIFICATION_CERTIFICATE = 'apple_server_notification_certificate';
}
