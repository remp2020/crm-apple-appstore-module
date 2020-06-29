# Apple AppStore Module

## Installation

We recommend using Composer for installation and update management. To add CRM Apple AppStore extension to your [REMP CRM](https://github.com/remp2020/crm-skeleton/) application use following command:

```bash
composer require remp/crm-apple-appstore-module
```

Enable installed extension in your `app/config/config.neon` file:

```neon
extensions:
	# ...
	- Crm\AppleAppstoreModule\DI\AppleAppstoreModuleExtension
```

Add database tables and seed Apple AppStore payment gateway and its configuration:

```bash
php bin/command.php phinx:migrate
php bin/command.php application:seed
```

## Configuration

TODO

## Enable Server-To-Server notifications

Apple Developer Documentation contains steps [how to enable Server-to-Server Notification](https://developer.apple.com/documentation/storekit/in-app_purchase/subscriptions_and_offers/enabling_server-to-server_notifications).

## Support Notes

- `unified_receipt.latest_receipt_info.quantity` must be 1. We allow only one subscription per payment.
