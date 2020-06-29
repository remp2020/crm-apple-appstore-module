<?php

namespace Crm\AppleAppstoreModule\Seeders;

use Crm\AppleAppstoreModule\Model\Config;
use Crm\ApplicationModule\Builder\ConfigBuilder;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Config\Repository\ConfigCategoriesRepository;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ConfigsTrait;
use Crm\ApplicationModule\Seeders\ISeeder;
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
    }
}
