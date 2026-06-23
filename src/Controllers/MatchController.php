<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaganShareController;
use App\Repositories\MatchRepository;
use App\Repositories\ParticipantRepository;
use App\Services\AuthService;
use App\Services\MatchGeneratorService;
use App\Support\BaganMatchGrouper;
use App\Support\BaganSettings;
use App\Support\FlashType;
use App\Support\MatchPairingMode;
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

        $matchRows = $this->matches->allWithParticipants($session->id);
        $matchesByBagan = BaganMatchGrouper::byRound($matchRows);
        $shareToken = null;
        $exportFilename = BaganShareController::exportFilename($session->name);

        if ($matchRows !== []) {
            $shareToken = $this->sessions->ensureShareToken($session->id);
        }

        return $view->render($response, 'pages/matches/index.twig', [
            'session' => $session,
            'participantCount' => $this->participants->countBySession($session->id),
            'matchCount' => count($matchRows),
            'matches' => $matchRows,
            'matchesByBagan' => $matchesByBagan,
            'baganSettings' => BaganSettings::fromSession($session),
            'pairingModes' => MatchPairingMode::options(),
            'shareToken' => $shareToken,
            'exportFilename' => $exportFilename,
        ]);
    }

    public function generate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $this->requireSession($sessionId);
        $data = (array) $request->getParsedBody();
        $settings = BaganSettings::fromRequest($data);

        $this->sessions->updateBaganSettings($sessionId, $settings, $this->userId());

        try {
            $count = $this->generator->autoGenerate($sessionId, $settings);
        } catch (\InvalidArgumentException $exception) {
            return $this->flashRedirect(
                $response,
                '/sessions/' . $sessionId . '/matches',
                $exception->getMessage(),
                FlashType::WARNING,
            );
        }

        $modeLabel = $settings->scope === BaganSettings::SCOPE_GLOBAL
            ? MatchPairingMode::options()[$settings->globalMode]
            : 'campuran per bagan';

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/matches',
            "{$count} match · {$settings->baganCount} bagan · {$modeLabel}",
            FlashType::CREATE,
            'Bagan digenerate',
        );
    }

    public function manual(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);
        $sessionId = $session->id;
        $participants = $this->participants->allBySession($sessionId);
        $matchRows = $this->matches->allWithParticipants($sessionId);
        $matchCounts = $this->matches->playerMatchCounts($sessionId);
        $matchesByBagan = BaganMatchGrouper::byRound($matchRows);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/matches/manual.twig', [
            'session' => $session,
            'participants' => $participants,
            'matches' => $matchRows,
            'matchesByBagan' => $matchesByBagan,
            'matchCounts' => $matchCounts,
            'baganSettings' => BaganSettings::fromSession($session),
            'pairingModes' => MatchPairingMode::options(),
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
            return $this->flashRedirect(
                $response,
                '/sessions/' . $sessionId . '/matches',
                'Data pairing tidak valid. Coba susun ulang.',
                FlashType::ERROR,
            );
        }

        $sessionParticipantIds = array_map(
            static fn ($participant) => $participant->id,
            $this->participants->allBySession($sessionId),
        );
        $sessionParticipantSet = array_flip($sessionParticipantIds);
        $sessionMatchIds = array_map(
            static fn (array $row) => $row['match']->id,
            $this->matches->allWithParticipants($sessionId),
        );
        $sessionMatchSet = array_flip($sessionMatchIds);
        $updated = 0;

        foreach ($pairings as $matchId => $slots) {
            if (!is_array($slots) || !isset($sessionMatchSet[(int) $matchId])) {
                continue;
            }

            $p1 = isset($slots['p1']) && $slots['p1'] !== '' ? (int) $slots['p1'] : null;
            $p2 = isset($slots['p2']) && $slots['p2'] !== '' ? (int) $slots['p2'] : null;

            if ($p1 !== null && !isset($sessionParticipantSet[$p1])) {
                continue;
            }

            if ($p2 !== null && !isset($sessionParticipantSet[$p2])) {
                continue;
            }

            if ($this->matches->updatePairing((int) $matchId, $sessionId, $p1, $p2)) {
                $updated++;
            }
        }

        if ($updated === 0 && $pairings !== []) {
            return $this->flashRedirect(
                $response,
                '/sessions/' . $sessionId . '/matches',
                'Tidak ada pairing valid yang disimpan. Periksa peserta dan slot match.',
                FlashType::WARNING,
            );
        }

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/matches',
            'Bagan berhasil disimpan.',
            FlashType::UPDATE,
        );
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        $matchRows = $this->matches->allWithParticipants($session->id);
        $matchesByBagan = BaganMatchGrouper::byRound($matchRows);
        $shareToken = null;
        $exportFilename = BaganShareController::exportFilename($session->name);

        if ($matchRows !== []) {
            $shareToken = $this->sessions->ensureShareToken($session->id);
        }

        return $view->render($response, 'pages/matches/preview.twig', [
            'session' => $session,
            'matches' => $matchRows,
            'matchesByBagan' => $matchesByBagan,
            'matchCounts' => $this->matches->playerMatchCounts($session->id),
            'baganSettings' => BaganSettings::fromSession($session),
            'pairingModes' => MatchPairingMode::options(),
            'shareToken' => $shareToken,
            'exportFilename' => $exportFilename,
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
