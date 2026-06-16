<?php

declare(strict_types=1);

namespace App\Twig;

use App\Support\AssetVersion;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AssetExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'asset',
                fn (string $path): string => AssetVersion::url($this->basePath, $path),
            ),
        ];
    }
}
