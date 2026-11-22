<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Offer\OfferValidator;
use App\Domain\Calculation\PriceCalculator;
use App\Http\MasterDataController;
use App\Http\CustomerProjectController;
use App\Http\OfferCalculationController;
use App\Http\SettingsController;
use App\Http\SystemPurgeController;
use App\Http\OrderController;
use App\Http\DocumentLockController;
use App\Http\UserAccessController;
use App\Http\EmployeeController;
use App\Http\DailyReportController;
use App\Http\MaterialRequestController;
use App\Http\MeasurementController;
use App\Http\PurchaseOrderController;
use App\Http\DocumentController;
use App\Http\TimeTrackingController;
use App\Http\CostCenterController;
use App\Http\MobileController;
use App\Infrastructure\Persistence\CostCenterRepository;
use App\Infrastructure\Persistence\TimeApprovalRepository;
use App\Infrastructure\Persistence\TimeTrackingRepository;
use App\Infrastructure\Persistence\AttachmentRepository;
use App\Infrastructure\Persistence\DocumentRepository;
use App\Http\WorkReportController;
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
use App\Infrastructure\Persistence\AttachmentStore;
use App\Infrastructure\Persistence\GeneratedDocumentRepository;
use App\Infrastructure\Persistence\MaterialRequestRepository;
use App\Infrastructure\Persistence\MeasurementRepository;
use App\Infrastructure\Persistence\PurchaseOrderRepository;
use App\Infrastructure\Persistence\WorkReportRepository;
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
        // Datenverzeichnisse im öffentlichen Webverzeichnis würden Dateien direkt ausliefern.
        $storageProblems = (new StoragePaths($this->config, $root))->blockingProblems();
        if ($storageProblems !== []) {
            throw new \RuntimeException('Unsichere Speicherkonfiguration: ' . implode(' ', $storageProblems));
        }
        $projects = new ProjectRepository($this->pdo);
        $offers = new OfferRepository($this->pdo);
        $store = new OfferJsonStore($this->config->offersPath, new OfferValidator());
        // Zentrale Modulregistrierung: Navigation/Templates (View), Routenprüfung (Application), Einstellungen.
        $modules = new ModuleService($this->pdo);
        $view = new View($root . '/templates', $modules);
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
        $dailyReportRepository=new DailyReportRepository($this->pdo);
        $documentStore=new AttachmentStore($this->config->documentsPath);
        $documentArchive=new DocumentArchive(new GeneratedDocumentRepository($this->pdo),$documentStore);
        $dailyReportService=new DailyReportService($this->pdo,$dailyReportRepository,$employeeRepository,new OrderRepository($this->pdo),$documentStore,new DocumentSettingsRepository($this->pdo));
        $measurementRepository=new MeasurementRepository($this->pdo);
        $measurementService=new MeasurementService($this->pdo,$measurementRepository,new OrderRepository($this->pdo),$numberRanges);
        $workReportService=new WorkReportService($this->pdo,new WorkReportRepository($this->pdo),new OrderRepository($this->pdo),$numberRanges,$priceCalculator,$documentStore,$orderService);
        $purchaseOrderService=new PurchaseOrderService($this->pdo,new PurchaseOrderRepository($this->pdo),$numberRanges,$documentStore,new PurchaseOrderPdfService($documentSettingsRepository));
        $materialRequestService=new MaterialRequestService(new MaterialRequestRepository($this->pdo),new OrderRepository($this->pdo),$dailyReportRepository,$modules);
        $documentService=new DocumentService($this->pdo,new AttachmentRepository($this->pdo),new DocumentRepository($this->pdo),$documentStore);
        $timeTracking=new TimeTrackingService(new TimeTrackingRepository($this->pdo),$documentStore,new CostTargets($this->pdo));
        $timeApprovals=new TimeApprovalService(new TimeApprovalRepository($this->pdo),new TimeTrackingRepository($this->pdo),$timeTracking,new OperationalSettingsService($this->pdo),new DocumentSettingsRepository($this->pdo),$documentStore,null,$dailyReportService);
        // Mobiles Frontend: dieselben Dienste, nur lesende Projektionen ohne kaufmännische Werte.
        $mobileWorkspace=new MobileWorkspace($modules,$orderService,$measurementService,$dailyReportService,$workReportService,$materialRequestService,$documentService,$timeTracking,$timeApprovals);

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
            new SettingsController($view,$settingsService,new OperationalSettingsService($this->pdo,$modules),$modules),
            $offerPdfService,
            new InternalCalculationPdfService($this->pdo,$documentSettingsRepository,new OfferValidator(),$offerCalculator),
            new OrderController($view,$orderService,$dailyReportService,$materialRequestService,$measurementService,$workReportService,$purchaseOrderService,$modules),
            new DocumentLockController($documentLockService),
            $documentLockService,
            new UserAccessController($userAccess=new UserAccessService($userRepository,$this->pdo,$employeeRepository),$view),
            new EmployeeController($view,new EmployeeService($employeeRepository,$userRepository,$this->pdo,$userAccess)),
            new DailyReportController($view,$dailyReportService,$materialRequestService,new DailyReportPdfService($dailyReportService,$documentSettingsRepository)),
            new MaterialRequestController($view,$materialRequestService),
            new MeasurementController($view,$measurementService,new MeasurementPdfService($measurementService,$measurementRepository,$documentSettingsRepository,$documentArchive)),
            new WorkReportController($view,$workReportService,new WorkReportPdfService($workReportService,$documentSettingsRepository,$documentArchive),$documentSettingsRepository),
            new PurchaseOrderController($view,$purchaseOrderService,$masterDataService),
            new DocumentController($view,$documentService),
            new TimeTrackingController($view,$timeTracking,$timeApprovals),
            new CostCenterController($view,new CostCenterService($this->pdo,new CostCenterRepository($this->pdo),dirname(__DIR__,2).'/config/cost-center-template.json')),
            $modules,
            new MobileController($view,$mobileWorkspace,$userRepository,$root.'/public',$workReportService,$modules,$documentSettingsRepository),
            new UserInterface($this->config->mobileHost),
            new SystemPurgeController($view, new SystemPurgeService($this->pdo,
                new SystemPurgePlanner($this->pdo, $this->config->documentsPath, $this->config->offersPath),
                new SystemPurgeBackup($this->pdo, $this->config->databasePath, $this->config->documentsPath, $this->config->offersPath,
                    $this->config->backupsPath !== '' ? $this->config->backupsPath : dirname($this->config->databasePath, 2) . '/backups'))),
        );
    }
}
