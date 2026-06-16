<?php

declare(strict_types=1);

namespace App\Support;

final class ParticipantFilter
{
    public const UNSET = '__unset__';

    public function __construct(
        public readonly string $search = '',
        public readonly ?string $rank = null,
        public readonly ?string $gender = null,
        public readonly ?string $gmsSource = null,
        public readonly string $basePath = '/participants',
    ) {
    }

    /** @param array<string, mixed> $params */
    public static function forSession(int $sessionId, array $params): self
    {
        $filter = self::fromQueryParams($params);

        return new self(
            $filter->search,
            $filter->rank,
            $filter->gender,
            $filter->gmsSource,
            '/sessions/' . $sessionId . '/participants',
        );
    }

    /** @param array<string, mixed> $params */
    public static function fromQueryParams(array $params): self
    {
        $search = trim((string) ($params['q'] ?? ''));

        $rank = Rank::normalize(trim((string) ($params['rank'] ?? '')));

        $genderRaw = trim((string) ($params['gender'] ?? ''));
        $gender = match (true) {
            $genderRaw === '' => null,
            $genderRaw === 'unset' => self::UNSET,
            in_array($genderRaw, ['male', 'female'], true) => $genderRaw,
            default => null,
        };

        $gmsRaw = trim((string) ($params['gms'] ?? ''));
        $gmsSource = match (true) {
            $gmsRaw === '' => null,
            $gmsRaw === 'unset' => self::UNSET,
            default => GmsSource::normalize($gmsRaw),
        };

        return new self($search, $rank, $gender, $gmsSource);
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->rank !== null
            || $this->gender !== null
            || $this->gmsSource !== null;
    }

    /** @return array<string, string> */
    public function toQueryParams(): array
    {
        $params = [];

        if ($this->search !== '') {
            $params['q'] = $this->search;
        }

        if ($this->rank !== null) {
            $params['rank'] = $this->rank;
        }

        if ($this->gender !== null) {
            $params['gender'] = $this->gender === self::UNSET ? 'unset' : $this->gender;
        }

        if ($this->gmsSource !== null) {
            $params['gms'] = $this->gmsSource === self::UNSET ? 'unset' : $this->gmsSource;
        }

        return $params;
    }

    /** Query string for pagination links, e.g. "q=calvin&rank=B&page=2&" */
    public function paginationQueryString(?int $page = null): string
    {
        $params = $this->toQueryParams();

        if ($page !== null && $page > 1) {
            $params['page'] = (string) $page;
        }

        $query = http_build_query($params);

        return $query !== '' ? $query . '&' : '';
    }

    public function url(array $overrides = []): string
    {
        $params = array_merge($this->toQueryParams(), $overrides);
        $params = array_filter($params, static fn (mixed $value): bool => $value !== null && $value !== '');

        $query = http_build_query($params);

        return $this->basePath . ($query !== '' ? '?' . $query : '');
    }

    public function resetUrl(): string
    {
        return $this->basePath;
    }

    public function toggleUrl(string $dimension, string $value): string
    {
        $paramKey = match ($dimension) {
            'rank' => 'rank',
            'gender' => 'gender',
            'gms' => 'gms',
            default => $dimension,
        };

        $storedValue = $value === self::UNSET ? 'unset' : $value;
        $current = $this->toQueryParams();

        if (($current[$paramKey] ?? '') === $storedValue) {
            unset($current[$paramKey]);
        } else {
            $current[$paramKey] = $storedValue;
        }

        $query = http_build_query($current);

        return $this->basePath . ($query !== '' ? '?' . $query : '');
    }

    public function isActive(string $dimension, string $value): bool
    {
        $storedValue = $value === self::UNSET ? 'unset' : $value;

        return match ($dimension) {
            'rank' => ($this->toQueryParams()['rank'] ?? '') === $storedValue,
            'gender' => ($this->toQueryParams()['gender'] ?? '') === $storedValue,
            'gms' => ($this->toQueryParams()['gms'] ?? '') === $storedValue,
            default => false,
        };
    }

    /** @return array{search: string, rank: ?string, gender: ?string, gmsSource: ?string} */
    public function repositoryFilters(): array
    {
        return [
            'search' => $this->search,
            'rank' => $this->rank,
            'gender' => $this->gender,
            'gmsSource' => $this->gmsSource,
        ];
    }
}
