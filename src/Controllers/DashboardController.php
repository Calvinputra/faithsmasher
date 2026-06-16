<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\GameRuleRepository;
use App\Repositories\MatchRepository;
use App\Repositories\ParticipantRepository;
use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class DashboardController extends BaseController
{
    private const PER_PAGE = 6;

    public function __construct(
        AuthService $auth,
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
        private readonly MatchRepository $matches = new MatchRepository(),
        private readonly GameRuleRepository $rules = new GameRuleRepository(),
    ) {
        parent::__construct($auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $search = (string) ($params['q'] ?? '');
        $page = max(1, (int) ($params['page'] ?? 1));

        $result = $this->sessions->paginateByUser($this->userId(), $search, $page, self::PER_PAGE);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/dashboard/index.twig', [
            'sessions' => $result['sessions'],
            'paginator' => $result['paginator'],
            'search' => $search,
            'flash' => $this->pullFlash(),
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['id']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/dashboard/session.twig', [
            'session' => $session,
            'participantCount' => $this->participants->countBySession($session->id),
            'ruleCount' => count($this->rules->allBySession($session->id)),
            'matchCount' => count($this->matches->allBySession($session->id)),
            'rankCounts' => $this->participants->countByRank($session->id),
            'flash' => $this->pullFlash(),
        ]);
    }
}
