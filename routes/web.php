<?php

declare(strict_types=1);

use App\Controllers\BaganShareController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\GameRuleController;
use App\Controllers\GlobalParticipantController;
use App\Controllers\HomeController;
use App\Controllers\MatchController;
use App\Controllers\ParticipantController;
use App\Controllers\SessionController;
use App\Controllers\UserAdminController;
use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app, AuthService $auth): void {
    $homeController = new HomeController();
    $authController = new AuthController($auth);
    $dashboardController = new DashboardController($auth);
    $sessionController = new SessionController($auth);
    $participantController = new ParticipantController($auth);
    $globalParticipantController = new GlobalParticipantController($auth);
    $gameRuleController = new GameRuleController($auth);
    $matchController = new MatchController($auth);
    $baganShareController = new BaganShareController();
    $userAdminController = new UserAdminController($auth);

    $app->get('/', [$homeController, 'index']);
    $app->get('/share/bagan/{token}', [$baganShareController, 'show']);

    $app->get('/login', [$authController, 'showLogin']);
    $app->post('/login', [$authController, 'login']);
    $app->get('/register', [$authController, 'showRegister']);
    $app->post('/register', [$authController, 'register']);
    $app->get('/logout', [$authController, 'logout']);

    $app->group('', function (RouteCollectorProxy $group) use (
        $dashboardController,
        $sessionController,
        $participantController,
        $gameRuleController,
        $matchController,
        $userAdminController,
        $globalParticipantController,
    ) {
        $group->get('/dashboard', [$dashboardController, 'index']);
        $group->get('/dashboard/{id}', [$dashboardController, 'show']);

        $group->get('/admin/users', [$userAdminController, 'index']);
        $group->post('/admin/users/{id}/approve', [$userAdminController, 'approve']);
        $group->post('/admin/users/{id}/reject', [$userAdminController, 'reject']);

        $group->get('/sessions/create', [$sessionController, 'create']);
        $group->post('/sessions', [$sessionController, 'store']);
        $group->get('/sessions/{id}/edit', [$sessionController, 'edit']);
        $group->post('/sessions/{id}', [$sessionController, 'update']);
        $group->post('/sessions/{id}/delete', [$sessionController, 'destroy']);

        $group->get('/participants', [$globalParticipantController, 'index']);
        $group->get('/participants/create', [$globalParticipantController, 'create']);
        $group->post('/participants', [$globalParticipantController, 'store']);
        $group->get('/participants/bulk/template', [$globalParticipantController, 'bulkTemplate']);
        $group->get('/participants/bulk', [$globalParticipantController, 'bulk']);
        $group->post('/participants/bulk', [$globalParticipantController, 'bulkStore']);
        $group->get('/participants/{id}/edit', [$globalParticipantController, 'edit']);
        $group->post('/participants/{id}/inline', [$globalParticipantController, 'inlineUpdate']);
        $group->post('/participants/{id}', [$globalParticipantController, 'update']);
        $group->post('/participants/{id}/delete', [$globalParticipantController, 'destroy']);

        $group->get('/sessions/{sessionId}/participants/bulk', [$participantController, 'bulk']);
        $group->post('/sessions/{sessionId}/participants/bulk', [$participantController, 'bulkStore']);
        $group->get('/sessions/{sessionId}/participants/assign', [$participantController, 'assign']);
        $group->post('/sessions/{sessionId}/participants/assign', [$participantController, 'assignStore']);
        $group->get('/sessions/{sessionId}/participants', [$participantController, 'index']);
        $group->post('/sessions/{sessionId}/participants/{id}/remove', [$participantController, 'unassign']);

        $group->get('/sessions/{sessionId}/rules', [$gameRuleController, 'index']);
        $group->get('/sessions/{sessionId}/rules/create', [$gameRuleController, 'create']);
        $group->post('/sessions/{sessionId}/rules', [$gameRuleController, 'store']);
        $group->get('/sessions/{sessionId}/rules/{id}/edit', [$gameRuleController, 'edit']);
        $group->post('/sessions/{sessionId}/rules/{id}', [$gameRuleController, 'update']);
        $group->post('/sessions/{sessionId}/rules/{id}/delete', [$gameRuleController, 'destroy']);

        $group->get('/sessions/{sessionId}/matches', [$matchController, 'index']);
        $group->post('/sessions/{sessionId}/matches/generate', [$matchController, 'generate']);
        $group->get('/sessions/{sessionId}/matches/manual', [$matchController, 'manual']);
        $group->post('/sessions/{sessionId}/matches/manual', [$matchController, 'saveManual']);
        $group->get('/sessions/{sessionId}/matches/preview', [$matchController, 'preview']);
        $group->get('/sessions/{sessionId}/matches/counter', [$matchController, 'counter']);
    })->add(new AuthMiddleware($auth));
};
