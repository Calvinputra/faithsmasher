<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaganShareController;
use App\Models\Session;
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
            'participants' => $this->participants->allBySession($session->id),
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
        } catch (\Throwable $exception) {
            return $this->flashRedirect(
                $response,
                '/sessions/' . $sessionId . '/matches',
                'Generate gagal: ' . $exception->getMessage(),
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

    public function regenerateBagan(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $baganNum = (int) ($args['baganNum'] ?? 0);
        $session = $this->requireSession($sessionId);
        $settings = BaganSettings::fromSession($session);

        if ($baganNum < 1 || $baganNum > $settings->baganCount) {
            return $this->respondBaganAction(
                $request,
                $response,
                $sessionId,
                $baganNum,
                'Nomor bagan tidak valid.',
                FlashType::ERROR,
                false,
            );
        }

        try {
            $this->generator->autoGenerate($sessionId, $settings, $baganNum);
        } catch (\Throwable $exception) {
            return $this->respondBaganAction(
                $request,
                $response,
                $sessionId,
                $baganNum,
                'Generate gagal: ' . $exception->getMessage(),
                FlashType::WARNING,
                false,
            );
        }

        return $this->respondBaganAction(
            $request,
            $response,
            $sessionId,
            $baganNum,
            "Bagan {$baganNum} berhasil digenerate ulang.",
            FlashType::UPDATE,
            true,
            'Generate bagan',
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
        $matchRows = $this->matches->allWithParticipants($sessionId);
        $sessionMatchSet = [];

        foreach ($matchRows as $row) {
            $sessionMatchSet[$row['match']->id] = $row['match']->roundNumber;
        }

        $baganNum = isset($data['bagan_num']) && is_numeric($data['bagan_num']) ? (int) $data['bagan_num'] : null;
        $updated = 0;

        foreach ($pairings as $matchId => $slots) {
            $matchIdInt = (int) $matchId;

            if (!is_array($slots) || !isset($sessionMatchSet[$matchIdInt])) {
                continue;
            }

            if ($baganNum !== null && $sessionMatchSet[$matchIdInt] !== $baganNum) {
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
            return $this->respondBaganAction(
                $request,
                $response,
                $sessionId,
                $baganNum ?? 0,
                'Tidak ada pairing valid yang disimpan. Periksa peserta dan slot match.',
                FlashType::WARNING,
                false,
            );
        }

        $flashMessage = $baganNum !== null
            ? "Manual setup Bagan {$baganNum} berhasil disimpan."
            : 'Bagan berhasil disimpan.';

        return $this->respondBaganAction(
            $request,
            $response,
            $sessionId,
            $baganNum ?? 0,
            $flashMessage,
            FlashType::UPDATE,
            $baganNum !== null,
            'Manual setup',
        );
    }

    public function requestBagan(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $baganNum = (int) ($args['baganNum'] ?? 0);
        $session = $this->requireSession($sessionId);
        $settings = BaganSettings::fromSession($session);

        if ($baganNum < 1 || $baganNum > $settings->baganCount) {
            return $this->respondBaganAction(
                $request,
                $response,
                $sessionId,
                $baganNum,
                'Nomor bagan tidak valid.',
                FlashType::ERROR,
                false,
            );
        }

        $data = (array) $request->getParsedBody();
        $requestsRaw = $data['requests'] ?? '[]';
        $requests = is_string($requestsRaw) ? json_decode($requestsRaw, true) : $requestsRaw;

        if (!is_array($requests)) {
            return $this->respondBaganAction(
                $request,
                $response,
                $sessionId,
                $baganNum,
                'Format request tidak valid.',
                FlashType::ERROR,
                false,
            );
        }

        try {
            if ($requests === []) {
                $this->generator->autoGenerate($sessionId, $settings, $baganNum);
            } else {
                $this->generator->regenerateBaganWithRequests($sessionId, $baganNum, $requests);
            }
        } catch (\Throwable $exception) {
            return $this->respondBaganAction(
                $request,
                $response,
                $sessionId,
                $baganNum,
                'Request match gagal: ' . $exception->getMessage(),
                FlashType::ERROR,
                false,
            );
        }

        return $this->respondBaganAction(
            $request,
            $response,
            $sessionId,
            $baganNum,
            "Bagan {$baganNum} diupdate. Request diterapkan, sisanya di-randomize.",
            FlashType::UPDATE,
            true,
            'Request match',
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

    private function wantsBaganPartial(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('X-Bagan-Partial') === '1') {
            return true;
        }

        return $this->wantsJson($request);
    }

    private function renderBaganSectionHtml(Twig $view, Session $session, int $baganNum): string
    {
        $matchesByBagan = BaganMatchGrouper::byRound($this->matches->allWithParticipants($session->id));

        return $view->getEnvironment()->render('components/matches/bagan-section.twig', [
            'session' => $session,
            'baganNum' => $baganNum,
            'baganMatches' => $matchesByBagan[$baganNum] ?? [],
            'baganSettings' => BaganSettings::fromSession($session),
            'isShareView' => false,
        ]);
    }

    private function respondBaganAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $sessionId,
        int $baganNum,
        string $message,
        string $type,
        bool $ok,
        ?string $title = null,
    ): ResponseInterface {
        if ($this->wantsBaganPartial($request) && $baganNum > 0 && $ok) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');
            $session = $this->requireSession($sessionId);

            return $this->json($response, [
                'ok' => true,
                'message' => $message,
                'title' => $title,
                'type' => $type,
                'baganNum' => $baganNum,
                'html' => $this->renderBaganSectionHtml($view, $session, $baganNum),
            ]);
        }

        if ($this->wantsBaganPartial($request)) {
            return $this->json($response, [
                'ok' => false,
                'message' => $message,
                'title' => $title,
                'type' => $type,
                'baganNum' => $baganNum > 0 ? $baganNum : null,
            ], $ok ? 200 : 422);
        }

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/matches',
            $message,
            $type,
            $title,
        );
    }
}
