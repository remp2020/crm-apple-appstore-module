<?php

namespace Crm\AppleAppstoreModule\Seeders;

use Crm\AppleAppstoreModule\Models\Config;
use Crm\ApplicationModule\Builder\ConfigBuilder;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Repositories\ConfigCategoriesRepository;
use Crm\ApplicationModule\Repositories\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ConfigsTrait;
use Crm\ApplicationModule\Seeders\ISeeder;
use Readdle\AppStoreServerAPI\Util\Helper;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigsSeeder implements ISeeder
{
    use ConfigsTrait;

    private $configCategoriesRepository;

    private $configsRepository;

    private $configBuilder;

    /** @var OutputInterface */
    private $output;

    public function __construct(
        ConfigCategoriesRepository $configCategoriesRepository,
        ConfigsRepository $configsRepository,
        ConfigBuilder $configBuilder
    ) {
        $this->configCategoriesRepository = $configCategoriesRepository;
        $this->configsRepository = $configsRepository;
        $this->configBuilder = $configBuilder;
    }

    public function seed(OutputInterface $output)
    {
        $categoryName = 'payments.config.category';
        $category = $this->configCategoriesRepository->loadByName($categoryName);
        if (!$category) {
            $output->writeln("  * <error>config category <info>$categoryName</info> is missing. Is <info>PaymentsModule</info> enabled?</error>");
        }

        $sorting = 1800;
        $this->addConfig(
            $output,
            $category,
            Config::SHARED_SECRET,
            ApplicationConfig::TYPE_STRING,
            'apple_appstore.config.shared_secret.display_name',
            'apple_appstore.config.shared_secret.description',
            '',
            $sorting++
        );
        $this->addConfig(
            $output,
            $category,
            Config::GATEWAY_MODE,
            ApplicationConfig::TYPE_STRING,
            'apple_appstore.config.gateway_mode.display_name',
            'apple_appstore.config.gateway_mode.description',
            'test',
            $sorting++
        );

        $sorting = 1850;
        $this->addConfig(
            $output,
            $category,
            Config::ISSUER_ID,
            ApplicationConfig::TYPE_STRING,
            'apple_appstore.config.issuer_id.display_name',
            'apple_appstore.config.issuer_id.description',
            null,
            $sorting++
        );
        $this->addConfig(
            $output,
            $category,
            Config::BUNDLE_ID,
            ApplicationConfig::TYPE_STRING,
            'apple_appstore.config.bundle_id.display_name',
            'apple_appstore.config.bundle_id.description',
            null,
            $sorting++
        );
        $this->addConfig(
            $output,
            $category,
            Config::APP_STORE_SERVER_API_KEY,
            ApplicationConfig::TYPE_STRING,
            'apple_appstore.config.api_key.display_name',
            'apple_appstore.config.api_key.description',
            null,
            $sorting++
        );
        $this->addConfig(
            $output,
            $category,
            Config::APP_STORE_SERVER_API_KEY_ID,
            ApplicationConfig::TYPE_STRING,
            'apple_appstore.config.api_key_id.display_name',
            'apple_appstore.config.api_key_id.description',
            null,
            $sorting++
        );
        $this->addConfig(
            $output,
            $category,
            Config::NOTIFICATION_CERTIFICATE,
            ApplicationConfig::TYPE_STRING,
            'apple_appstore.config.notification_certificate.display_name',
            'apple_appstore.config.notification_certificate.description',
            Helper::toPEM(file_get_contents('https://www.apple.com/certificateauthority/AppleRootCA-G3.cer')),
            $sorting++
        );

        $category = $this->getCategory($output, 'subscriptions.config.users.category', 'fa fa-user', 300);

        $this->addConfig(
            $output,
            $category,
            Config::APPLE_BLOCK_ANONYMIZATION,
            ApplicationConfig::TYPE_BOOLEAN,
            'apple_appstore.config.users.prevent_anonymization.name',
            'apple_appstore.config.users.prevent_anonymization.description',
            true,
            200
        );
    }
}
