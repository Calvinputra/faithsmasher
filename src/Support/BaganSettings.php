<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Session;

final class BaganSettings
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_PER_BAGAN = 'per_bagan';

    public function __construct(
        public readonly int $baganCount,
        public readonly string $scope,
        public readonly string $globalMode,
        /** @var list<string> */
        public readonly array $perBaganModes,
    ) {
    }

    public static function fromSession(Session $session): self
    {
        $modes = $session->baganPairingModes ?? [];
        $baganCount = max(1, min(20, $session->baganCount));

        while (count($modes) < $baganCount) {
            $modes[] = $session->baganPairingMode;
        }

        return new self(
            $baganCount,
            $session->baganPairingScope,
            $session->baganPairingMode,
            array_slice(array_map(MatchPairingMode::normalize(...), $modes), 0, $baganCount),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromRequest(array $data): self
    {
        $baganCount = max(1, min(20, (int) ($data['bagan_count'] ?? 1)));
        $scope = ($data['bagan_pairing_scope'] ?? self::SCOPE_GLOBAL) === self::SCOPE_PER_BAGAN
            ? self::SCOPE_PER_BAGAN
            : self::SCOPE_GLOBAL;
        $globalMode = MatchPairingMode::normalize((string) ($data['bagan_pairing_mode'] ?? MatchPairingMode::RANK));

        /** @var list<string> $perBaganModes */
        $perBaganModes = [];
        $rawModes = $data['bagan_modes'] ?? [];

        if (is_array($rawModes)) {
            for ($i = 1; $i <= $baganCount; $i++) {
                $perBaganModes[] = MatchPairingMode::normalize((string) ($rawModes[$i] ?? $globalMode));
            }
        }

        while (count($perBaganModes) < $baganCount) {
            $perBaganModes[] = $globalMode;
        }

        return new self($baganCount, $scope, $globalMode, $perBaganModes);
    }

    public function modeForBagan(int $baganNumber): string
    {
        if ($this->scope === self::SCOPE_GLOBAL) {
            return $this->globalMode;
        }

        $index = $baganNumber - 1;

        return $this->perBaganModes[$index] ?? $this->globalMode;
    }

    /** @return array<string, mixed> */
    public function toSessionColumns(): array
    {
        return [
            'bagan_count' => $this->baganCount,
            'bagan_pairing_scope' => $this->scope,
            'bagan_pairing_mode' => $this->globalMode,
            'bagan_pairing_modes' => json_encode($this->perBaganModes, JSON_THROW_ON_ERROR),
        ];
    }
}
