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
        \App\Database\SchemaEnsurer::ensure();
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

        $environment = $twig->getEnvironment();
        $environment->addGlobal('app', $config);
        $environment->addExtension(new \App\Twig\AssetExtension($this->basePath));

        $auth = new \App\Services\AuthService();
        $twig->getEnvironment()->addGlobal('currentUser', $auth->user());

        $app = AppFactory::create();
        $app->add(new \App\Middleware\FlashMiddleware($twig));
        $app->add(TwigMiddleware::create($app, $twig));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(
            false,
            true,
            true,
            $logger
        );

        $defaultHandler = function ($request, $exception, $displayErrorDetails) use ($twig, $config) {
            $response = new Response();
            $status = method_exists($exception, 'getCode') && $exception->getCode() >= 400 && $exception->getCode() < 600
                ? (int) $exception->getCode()
                : 500;

            if ($exception instanceof HttpNotFoundException) {
                return $twig->render($response->withStatus(404), 'pages/errors/404.twig');
            }

            return $twig->render($response->withStatus($status >= 400 ? $status : 500), 'pages/errors/error.twig', [
                'message' => $config['debug']
                    ? $exception->getMessage()
                    : 'Please try again or contact support if the problem persists.',
                'debug' => $config['debug'],
                'exception' => $exception,
            ]);
        };

        $errorMiddleware->setDefaultErrorHandler($defaultHandler);

        $errorMiddleware->setErrorHandler(
            HttpNotFoundException::class,
            function ($request, $exception, $displayErrorDetails) use ($twig) {
                $response = new Response();

                return $twig->render($response->withStatus(404), 'pages/errors/404.twig');
            }
        );

        $registerRoutes = require $this->basePath . '/routes/web.php';
        $registerRoutes($app, $auth);

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
