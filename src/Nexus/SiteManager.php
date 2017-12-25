<?php

namespace Sztyup\Nexus;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Contracts\View\Factory;
use Sztyup\Nexus\Exceptions\SiteNotFoundException;
use Sztyup\Nexus\Middleware\InjectCrossDomainLogin;
use Sztyup\Nexus\Middleware\StartSession;

class SiteManager
{
    /** @var Request */
    protected $request;

    /** @var  Factory */
    protected $viewFactory;

    /** @var UrlGenerator */
    protected $urlGenerator;

    /** @var  Repository */
    protected $config;

    /** @var  Router */
    protected $router;


    /** @var Collection */
    protected $sites;

    /** @var  int */
    private $currentId;

    public function __construct(Container $container)
    {
        $this->sites = new Collection();
        $this->request = $container->make(Request::class);
        $this->viewFactory = $container->make(Factory::class);
        $this->urlGenerator = $container->make(UrlGenerator::class);
        $this->router = $container->make(Router::class);
        $this->config = $container->make(Repository::class)->get('nexus');

        $this->loadSitesFromRepo($container);
        $this->determineCurrentSite();
    }

    protected function determineCurrentSite()
    {
        if ($this->isConsole()) {
            return null;
        }

        $currentSite = $this->getByDomain($host = $this->request->getHost());
        if ($currentSite == null) {
            throw new SiteNotFoundException($host);
        }

        $this->currentId = $currentSite->getId();

        return $currentSite;
    }

    protected function loadSitesFromRepo(Container $container)
    {
        $repositoryClass = $this->config['model_repository'];

        // Check if it implements required Contract
        $reflection = new \ReflectionClass($repositoryClass);
        if (!$reflection->implementsInterface(SiteRepositoryContract::class)) {
            throw new \Exception('Configured repository does not implement SiteRepositoryContract');
        }

        // Instantiate repo
        /** @var SiteRepositoryContract $repository */
        $repository= $container->make($repositoryClass);

        // Add each of the sites to the collection
        /** @var SiteModelContract $siteModel */
        foreach ($repository->getAll() as $siteModel) {
            $this->sites->put(
                $siteModel->getId(),
                $container->make(Site::class, ['site' => $siteModel])
            );
        }
    }

    public function registerRoutes()
    {
        /*
         * Main domain, where the central authentication takes place, can be moved by enviroment,
         * and independent of the sites table, and much else
         */
        $this->router->group([
            'middleware' => ['nexus', 'web'],
            'domain' => $this->config['main_domain'],
            'as' => 'main.',
            'namespace' => $this->config['route_namespace'] . '\\Main'
        ], $this->config['directories']['routes'] . DIRECTORY_SEPARATOR . 'main.php');

        /*
        * Resource routes, to handle resources for each site
        * Its needed to avoid eg. golya.sch.bme.hu/js/golya/app.js, instead we can use golya.sch.bme.hu/js/app.js
        */
        $this->router->get(
            'img/{path}',
            [
                'uses' => 'Sztyup\Nexus\Controllers\ResourceController@image',
                'as' => 'resource.img',
                'where' => ['path' => '.*']
            ]
        );
        $this->router->get(
            'js/{path}',
            [
                'uses' => 'Sztyup\Nexus\Controllers\ResourceController@js',
                'as' => 'resource.js',
                'where' => ['path' => '.*']
            ]
        );
        $this->router->get(
            'css/{path}',
            [
                'uses' => 'Sztyup\Nexus\Controllers\ResourceController@css',
                'as' => 'resource.css',
                'where' => ['path' => '.*']
            ]
        );

        // Global route group
        $this->router->group([
            'middleware' => ['nexus', 'web'],
            'namespace' => $this->config['route_namespace']
        ], function () {
            /* Global routes applied to each site */
            include $this->config['directories']['routes'] . DIRECTORY_SEPARATOR . 'global.php';

            /* Register each site's route */
            foreach ($this->all() as $site) {
                $site->registerRoutes();
            }
        });

        $this->router->getRoutes()->refreshActionLookups();
        $this->router->getRoutes()->refreshNameLookups();
    }

    protected function findBy($field, $value): Collection
    {
        return $this->sites->filter(function (Site $site) use ($field, $value) {
            return $site->{"get" . ucfirst($field)}() == $value;
        });
    }

    public function current()
    {
        if ($this->isConsole()) {
            return null;
        }

        return $this->sites[$this->currentId];
    }

    public function getByDomain(string $domain): Site
    {
        return $this->findBy('domain', $domain)->first();
    }

    public function getBySlug(string $slug): Site
    {
        return $this->findBy('slug', $slug)->first();
    }

    public function getById(int $id): Site
    {
        return $this->sites->get($id);
    }

    /**
     * @return Collection|Site[]
     */
    public function all(): Collection
    {
        return $this->sites;
    }

    private function isConsole(): bool
    {
        return php_sapi_name() == 'cli' || php_sapi_name() == 'phpdbg';
    }

    public function impersonate(int $userId)
    {
        $this->request->session()->put('_nexus_impersonate', $userId);
    }

    public function stopImpersonating()
    {
        $this->request->session()->forget('_nexus_impersonate');
    }

    public function isImpersonating()
    {
        $this->request->session()->has('_nexus_impersonate');
    }

    /**
     * Direct every call to the current site
     *
     * @param $name
     * @param $arguments
     * @return null
     */
    public function __call($name, $arguments)
    {
        /*
         * If running in console then we dont have a current site
         */
        if ($this->isConsole()) {
            return null;
        }

        if (method_exists($this->current(), $name)) {
            return $this->current()->{$name}(...$arguments);
        }

        throw new \BadMethodCallException('Method[' . $name . '] does not exists on Site');
    }
}
