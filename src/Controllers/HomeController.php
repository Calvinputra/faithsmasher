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
            'features' => [
                [
                    'title' => 'Bagan Pertandingan',
                    'description' => 'Buat dan kelola bracket turnamen badminton single & double.',
                ],
                [
                    'title' => 'Pencatatan Skor',
                    'description' => 'Catat skor setiap game dan lihat riwayat pertandingan secara real-time.',
                ],
                [
                    'title' => 'Manajemen Peserta',
                    'description' => 'Daftarkan pemain, atur seeding, dan pantau progres turnamen.',
                ],
            ],
        ]);
    }
}
