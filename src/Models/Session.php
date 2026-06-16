<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BaganSettings;
use App\Support\MatchPairingMode;

final class Session
{
    /**
     * @param list<string>|null $baganPairingModes
     */
    public function __construct(
        public readonly int $id,
        public readonly int $createdByUserId,
        public readonly string $name,
        public readonly string $sessionDate,
        public readonly string $location,
        public readonly int $courtCount,
        public readonly int $baganCount,
        public readonly string $baganPairingScope,
        public readonly string $baganPairingMode,
        public readonly ?array $baganPairingModes,
        public readonly ?string $baganShareToken,
        public readonly string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?int $updatedByUserId,
        public readonly ?string $createdByName = null,
        public readonly ?string $updatedByName = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $modes = null;

        if (isset($row['bagan_pairing_modes']) && $row['bagan_pairing_modes'] !== null && $row['bagan_pairing_modes'] !== '') {
            $decoded = json_decode((string) $row['bagan_pairing_modes'], true);
            $modes = is_array($decoded)
                ? array_values(array_map(MatchPairingMode::normalize(...), $decoded))
                : null;
        }

        return new self(
            (int) $row['id'],
            (int) $row['user_id'],
            $row['name'],
            $row['session_date'],
            $row['location'] ?? '',
            (int) ($row['court_count'] ?? 1),
            max(1, (int) ($row['bagan_count'] ?? 1)),
            ($row['bagan_pairing_scope'] ?? BaganSettings::SCOPE_GLOBAL) === BaganSettings::SCOPE_PER_BAGAN
                ? BaganSettings::SCOPE_PER_BAGAN
                : BaganSettings::SCOPE_GLOBAL,
            MatchPairingMode::normalize((string) ($row['bagan_pairing_mode'] ?? MatchPairingMode::RANK)),
            $modes,
            isset($row['bagan_share_token']) && $row['bagan_share_token'] !== null && $row['bagan_share_token'] !== ''
                ? (string) $row['bagan_share_token']
                : null,
            (string) $row['created_at'],
            isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            isset($row['updated_by_user_id']) && $row['updated_by_user_id'] !== null
                ? (int) $row['updated_by_user_id']
                : null,
            isset($row['created_by_name']) ? (string) $row['created_by_name'] : null,
            isset($row['updated_by_name']) ? (string) $row['updated_by_name'] : null,
        );
    }

    /** @deprecated Use $createdByUserId */
    public function userId(): int
    {
        return $this->createdByUserId;
    }

    public function wasUpdated(): bool
    {
        return $this->updatedByUserId !== null
            && $this->updatedAt !== null
            && $this->updatedAt !== $this->createdAt;
    }
}
