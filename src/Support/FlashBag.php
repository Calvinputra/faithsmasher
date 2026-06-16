<?php

declare(strict_types=1);

namespace App\Support;

final class FlashBag
{
    public static function set(string $message, string $type = FlashType::SUCCESS, ?string $title = null): void
    {
        $_SESSION['flash'] = [
            'type' => FlashType::isValid($type) ? $type : FlashType::SUCCESS,
            'title' => $title ?? FlashType::title($type),
            'message' => $message,
        ];
    }

    /** @return array{type: string, title: string, message: string}|null */
    public static function pull(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        if (!is_array($flash) || !isset($flash['message'])) {
            return null;
        }

        $type = (string) ($flash['type'] ?? FlashType::SUCCESS);

        return [
            'type' => FlashType::isValid($type) ? $type : FlashType::SUCCESS,
            'title' => (string) ($flash['title'] ?? FlashType::title($type)),
            'message' => (string) $flash['message'],
        ];
    }
}
