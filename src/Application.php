<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

final class Application
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__);
    }

    public function run(): void
    {
        $this->loadEnvironment();
        $this->ensureStorageDirectories();
        $this->createApp()->run();
    }

    private function createApp(): App
    {
        $config = require $this->basePath . '/config/app.php';

        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler(
            $this->basePath . '/storage/logs/app.log',
            $config['debug'] ? Level::Debug : Level::Error
        ));

        $twig = Twig::create($this->basePath . '/resources/views', [
            'cache' => $config['debug'] ? false : $this->basePath . '/storage/cache/views',
            'auto_reload' => $config['debug'],
        ]);

        $twig->getEnvironment()->addGlobal('app', $config);

        $app = AppFactory::create();
        $app->add(TwigMiddleware::create($app, $twig));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(
            $config['debug'],
            true,
            true,
            $logger
        );

        $errorMiddleware->setErrorHandler(
            HttpNotFoundException::class,
            function ($request, $exception, $displayErrorDetails) use ($twig) {
                $response = new Response();

                return $twig->render($response->withStatus(404), 'pages/errors/404.twig');
            }
        );

        $registerRoutes = require $this->basePath . '/routes/web.php';
        $registerRoutes($app);

        return $app;
    }

    private function loadEnvironment(): void
    {
        $envFile = $this->basePath . '/.env';

        if (is_file($envFile)) {
            Dotenv::createImmutable($this->basePath)->safeLoad();
        }
    }

    private function ensureStorageDirectories(): void
    {
        $directories = [
            $this->basePath . '/storage/logs',
            $this->basePath . '/storage/cache/views',
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }
}
