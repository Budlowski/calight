<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Offer\OfferValidator;
use App\Domain\Calculation\PriceCalculator;
use App\Http\MasterDataController;
use App\Http\CustomerProjectController;
use App\Http\Router;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\OfferJsonStore;
use App\Infrastructure\Persistence\OfferRepository;
use App\Infrastructure\Persistence\ProjectRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Persistence\MasterDataRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Infrastructure\Persistence\CustomerProjectRepository;
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
        $masterData = new MasterDataController(
            $view,
            new MasterDataService(new MasterDataRepository($this->pdo), new PriceCalculator(), new NumberRangeRepository($this->pdo)),
        );
        $numberRanges = new NumberRangeRepository($this->pdo);
        $customerProjects = new CustomerProjectController(
            $view,
            new CustomerProjectService(new CustomerProjectRepository($this->pdo), $numberRanges),
        );

        return new Application(
            new Router(),
            $view,
            new UserRepository($this->pdo),
            $projects,
            $offers,
            new OfferService($this->pdo, $projects, $offers, $store),
            $masterData,
            $customerProjects,
        );
    }
}
