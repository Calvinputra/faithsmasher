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
        $date = (string) ($params['date'] ?? '');
        $page = max(1, (int) ($params['page'] ?? 1));

        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = '';
        }

        $userId = $this->userId();
        $result = $this->sessions->paginateByUser($userId, $search, $date, $page, self::PER_PAGE);
        $stats = $this->sessions->statsByUser($userId, $search, $date);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/dashboard/index.twig', [
            'sessions' => $result['sessions'],
            'paginator' => $result['paginator'],
            'search' => $search,
            'date' => $date,
            'sessionDates' => $this->sessions->distinctDatesByUser($userId),
            'stats' => $stats,
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
        ]);
    }
}
