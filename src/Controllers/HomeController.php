<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class HomeController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/home.twig', [
            'phpVersion' => PHP_VERSION,
            'features' => [
                [
                    'title' => 'Slim Framework',
                    'description' => 'Routing PSR-7 yang ringan dan cepat untuk API maupun halaman web.',
                ],
                [
                    'title' => 'Twig Templates',
                    'description' => 'Template engine aman dengan layout, component, dan inheritance.',
                ],
                [
                    'title' => 'Tailwind CSS',
                    'description' => 'Utility-first CSS modern — tanpa Bootstrap. Build production-ready dengan npm run build.',
                ],
            ],
        ]);
    }
}
