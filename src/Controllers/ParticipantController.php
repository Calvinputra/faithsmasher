<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ParticipantRepository;
use App\Services\AuthService;
use App\Support\Gender;
use App\Support\Rank;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class ParticipantController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
    ) {
        parent::__construct($auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/index.twig', [
            'session' => $session,
            'participants' => $this->participants->allBySession($session->id),
            'rankCounts' => $this->participants->countByRank($session->id),
            'genders' => Gender::labels(),
            'flash' => $this->pullFlash(),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/form.twig', [
            'session' => $session,
            'participant' => null,
            'ranks' => Rank::options(),
            'genders' => Gender::labels(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/form.twig', [
                'session' => $session,
                'participant' => null,
                'ranks' => Rank::options(),
                'genders' => Gender::labels(),
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $this->participants->create(
            $sessionId,
            trim((string) $data['name']),
            (string) $data['rank'],
            $this->nullableString($data['gender'] ?? null),
            $this->nullableString($data['phone'] ?? null),
            $this->nullableString($data['gms_source'] ?? null),
        );

        return $this->flashRedirect($response, '/sessions/' . $sessionId . '/participants', 'Participant added.');
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $participant = $this->participants->find((int) $args['id'], $sessionId);

        if ($participant === null) {
            return $this->redirect($response, '/sessions/' . $sessionId . '/participants');
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/form.twig', [
            'session' => $session,
            'participant' => $participant,
            'ranks' => Rank::options(),
            'genders' => Gender::labels(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $participantId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/form.twig', [
                'session' => $session,
                'participant' => $this->participants->find($participantId, $sessionId),
                'ranks' => Rank::options(),
                'genders' => Gender::labels(),
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $this->participants->update(
            $participantId,
            $sessionId,
            trim((string) $data['name']),
            (string) $data['rank'],
            $this->nullableString($data['gender'] ?? null),
            $this->nullableString($data['phone'] ?? null),
            $this->nullableString($data['gms_source'] ?? null),
        );

        return $this->flashRedirect($response, '/sessions/' . $sessionId . '/participants', 'Participant updated.');
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $this->participants->delete((int) $args['id'], $sessionId);

        return $this->flashRedirect($response, '/sessions/' . $sessionId . '/participants', 'Participant deleted.');
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    private function validate(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = 'Name is required.';
        }

        $rank = (string) ($data['rank'] ?? '');
        if (!Rank::isValid($rank)) {
            $errors['rank'] = 'Rank is required.';
        }

        $gender = $this->nullableString($data['gender'] ?? null);
        if (!Gender::isValid($gender)) {
            $errors['gender'] = 'Invalid gender value.';
        }

        return $errors;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }
}
