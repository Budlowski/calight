<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Offer\OfferValidator;
use App\Domain\Calculation\PriceCalculator;
use App\Http\MasterDataController;
use App\Http\CustomerProjectController;
use App\Http\OfferCalculationController;
use App\Http\SettingsController;
use App\Domain\Offer\OfferCalculator;
use App\Http\Router;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\MasterDataRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\CustomerProjectRepository;
use App\Infrastructure\Persistence\DocumentSettingsRepository;
use App\View\View;
use PDO;

final readonly class Bootstrap
{
    public function __construct(public Config $config, public PDO $pdo)
    {
    }

    public static function create(string $root): self
    {
        /** @var Config $config */
        $config = require $root . '/config/app.php';
        date_default_timezone_set($config->timezone);
        return new self($config, ConnectionFactory::create($config->databasePath));
    }

    public function application(string $root): Application
    {
        $projects = new ProjectRepository($this->pdo);
        $offers = new OfferRepository($this->pdo);
        $store = new OfferJsonStore($this->config->offersPath, new OfferValidator());
        $view = new View($root . '/templates');
        $priceCalculator=new PriceCalculator();
        $offerCalculator=new OfferCalculator($priceCalculator);
        $masterDataService=new MasterDataService(new MasterDataRepository($this->pdo),$priceCalculator,new NumberRangeRepository($this->pdo));
        $masterData = new MasterDataController($view,$masterDataService);
        $numberRanges = new NumberRangeRepository($this->pdo);
        $customerProjects = new CustomerProjectController(
            $view,
            new CustomerProjectService(new CustomerProjectRepository($this->pdo), $numberRanges),
        );
        $documentSettingsRepository=new DocumentSettingsRepository($this->pdo);
        $settingsService=new DocumentSettingsService($documentSettingsRepository);

        return new Application(
            new Router(),
            $view,
            new UserRepository($this->pdo),
            $projects,
            $offers,
            new OfferService($this->pdo, $projects, $offers, $store,$offerCalculator),
            $masterData,
            $customerProjects,
            new OfferCalculationController(new OfferCalculationService($masterDataService,new OfferValidator(),$offerCalculator)),
            new SettingsController($view,$settingsService),
            new OfferPdfService($this->pdo,$documentSettingsRepository,new OfferValidator(),$offerCalculator),
            new InternalCalculationPdfService($this->pdo,$documentSettingsRepository,new OfferValidator(),$offerCalculator),
        );
    }
}
