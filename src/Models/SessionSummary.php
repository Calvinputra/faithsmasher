<?php

declare(strict_types=1);

namespace App\Models;

final class SessionSummary
{
    public function __construct(
        public readonly Session $session,
        public readonly int $participantCount,
    ) {
    }
}
