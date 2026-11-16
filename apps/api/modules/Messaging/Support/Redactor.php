<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Support;

/** Message text must never carry secrets: PINs, passwords, tokens and the like are masked before queueing. */
final class Redactor
{
    public static function clean(string $text): string
    {
        $text = preg_replace('/\b(pin|passcode|password|passwd|secret|token|otp)\b(\s*(?:is|=|:)?\s*)\S+/i', '$1$2[hidden]', $text) ?? $text;

        return trim($text);
    }

    /** A template parameter may not hold newlines, tabs or long runs of spaces. */
    public static function param(string $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', self::clean($text)) ?? ''), 0, 600);
    }

    public static function mask(string $phone): string
    {
        $n = strlen($phone);

        return $n <= 7 ? str_repeat('*', $n) : substr($phone, 0, 4).str_repeat('*', $n - 7).substr($phone, -3);
    }
}
