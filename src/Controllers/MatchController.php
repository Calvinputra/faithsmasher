<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MatchRepository;
use App\Repositories\ParticipantRepository;
use App\Services\AuthService;
use App\Services\MatchGeneratorService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class MatchController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
        private readonly MatchRepository $matches = new MatchRepository(),
        private readonly MatchGeneratorService $generator = new MatchGeneratorService(),
    ) {
        parent::__construct($auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/matches/index.twig', [
            'session' => $session,
            'participantCount' => $this->participants->countBySession($session->id),
            'matchCount' => count($this->matches->allBySession($session->id)),
            'flash' => $this->pullFlash(),
        ]);
    }

    public function generate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $this->requireSession($sessionId);

        try {
            $count = $this->generator->autoGenerate($sessionId);
            $message = "Auto-generated {$count} match(es) by rank skill.";
        } catch (\InvalidArgumentException $exception) {
            return $this->flashRedirect(
                $response,
                '/sessions/' . $sessionId . '/matches',
                $exception->getMessage(),
                'error',
            );
        }

        return $this->flashRedirect($response, '/sessions/' . $sessionId . '/matches/preview', $message);
    }

    public function manual(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);
        $sessionId = $session->id;
        $participants = $this->participants->allBySession($sessionId);
        $matchRows = $this->matches->allWithParticipants($sessionId);
        $matchCounts = $this->matches->playerMatchCounts($sessionId);

        if ($matchRows === [] && count($participants) >= 2) {
            $this->generator->autoGenerate($sessionId);
            $matchRows = $this->matches->allWithParticipants($sessionId);
            $matchCounts = $this->matches->playerMatchCounts($sessionId);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/matches/manual.twig', [
            'session' => $session,
            'participants' => $participants,
            'matches' => $matchRows,
            'matchCounts' => $matchCounts,
            'flash' => $this->pullFlash(),
        ]);
    }

    public function saveManual(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $this->requireSession($sessionId);
        $data = (array) $request->getParsedBody();
        $pairings = $data['pairings'] ?? [];

        if (is_string($pairings)) {
            $pairings = json_decode($pairings, true) ?? [];
        }

        if (!is_array($pairings)) {
            return $this->flashRedirect($response, '/sessions/' . $sessionId . '/matches/manual', 'Invalid pairing data.', 'error');
        }

        foreach ($pairings as $matchId => $slots) {
            if (!is_array($slots)) {
                continue;
            }

            $p1 = isset($slots['p1']) && $slots['p1'] !== '' ? (int) $slots['p1'] : null;
            $p2 = isset($slots['p2']) && $slots['p2'] !== '' ? (int) $slots['p2'] : null;

            $this->matches->updatePairing((int) $matchId, $sessionId, $p1, $p2);
        }

        return $this->flashRedirect($response, '/sessions/' . $sessionId . '/matches/preview', 'Manual bracket saved.');
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/matches/preview.twig', [
            'session' => $session,
            'matches' => $this->matches->allWithParticipants($session->id),
            'matchCounts' => $this->matches->playerMatchCounts($session->id),
            'flash' => $this->pullFlash(),
        ]);
    }

    public function counter(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);
        $participants = $this->participants->allBySession($session->id);
        $matchCounts = $this->matches->playerMatchCounts($session->id);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/matches/counter.twig', [
            'session' => $session,
            'participants' => $participants,
            'matchCounts' => $matchCounts,
            'rankCounts' => $this->participants->countByRank($session->id),
        ]);
    }
}
