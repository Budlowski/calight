<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Offer\OfferValidator;
use App\Domain\Calculation\PriceCalculator;
use App\Http\MasterDataController;
use App\Http\CustomerProjectController;
use App\Http\OfferCalculationController;
use App\Http\SettingsController;
use App\Http\OrderController;
use App\Http\DocumentLockController;
use App\Http\UserAccessController;
use App\Http\EmployeeController;
use App\Http\DailyReportController;
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
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\DocumentLockRepository;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\DailyReportRepository;
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
        $offerPdfService=new OfferPdfService($this->pdo,$documentSettingsRepository,new OfferValidator(),$offerCalculator);
        $offerService=new OfferService($this->pdo,$projects,$offers,$store,$offerCalculator,$numberRanges,$offerPdfService);
        $orderService=new OrderService($this->pdo,new OrderRepository($this->pdo),$offers,$offerService,$numberRanges);
        $documentLockService=new DocumentLockService($this->pdo,new DocumentLockRepository($this->pdo));
        $userRepository=new UserRepository($this->pdo);
        $employeeRepository=new EmployeeRepository($this->pdo);
        $dailyReportService=new DailyReportService($this->pdo,new DailyReportRepository($this->pdo),$employeeRepository,new OrderRepository($this->pdo));

        return new Application(
            new Router(),
            $view,
            $userRepository,
            $projects,
            $offers,
            $offerService,
            $masterData,
            $customerProjects,
            new OfferCalculationController(new OfferCalculationService($masterDataService,new OfferValidator(),$offerCalculator)),
            new SettingsController($view,$settingsService),
            $offerPdfService,
            new InternalCalculationPdfService($this->pdo,$documentSettingsRepository,new OfferValidator(),$offerCalculator),
            new OrderController($view,$orderService,$dailyReportService),
            new DocumentLockController($documentLockService),
            $documentLockService,
            new UserAccessController(new UserAccessService($userRepository)),
            new EmployeeController($view,new EmployeeService($employeeRepository,$userRepository)),
            new DailyReportController($view,$dailyReportService),
        );
    }
}
