<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use Slim\App;

return function (App $app): void {
    $homeController = new HomeController();

    $app->get('/', [$homeController, 'index']);
};
