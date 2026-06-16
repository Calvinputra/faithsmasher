<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MatchRepository;
use App\Repositories\SessionRepository;
use App\Support\BaganMatchGrouper;
use App\Support\BaganSettings;
use App\Support\MatchPairingMode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

final class BaganShareController
{
    public function __construct(
        private readonly SessionRepository $sessions = new SessionRepository(),
        private readonly MatchRepository $matches = new MatchRepository(),
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = trim((string) ($args['token'] ?? ''));

        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new HttpNotFoundException($request);
        }

        $session = $this->sessions->findByShareToken($token);

        if ($session === null) {
            throw new HttpNotFoundException($request);
        }

        $matchRows = $this->matches->allWithParticipants($session->id);

        if ($matchRows === []) {
            throw new HttpNotFoundException($request);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/matches/share.twig', [
            'session' => $session,
            'matchesByBagan' => BaganMatchGrouper::byRound($matchRows),
            'baganSettings' => BaganSettings::fromSession($session),
            'pairingModes' => MatchPairingMode::options(),
            'exportFilename' => self::exportFilename($session->name),
        ]);
    }

    public static function exportFilename(string $sessionName): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(trim($sessionName))) ?? 'bagan';
        $slug = trim($slug, '-');

        return ($slug !== '' ? $slug : 'bagan') . '-bagan.png';
    }
}
