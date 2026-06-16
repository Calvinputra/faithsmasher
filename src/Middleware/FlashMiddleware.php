<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\FlashBag;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

final class FlashMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Twig $twig,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->twig->getEnvironment()->addGlobal('flash', FlashBag::pull());

        return $handler->handle($request);
    }
}
