<?php

namespace App\Services\Pixelforge;

final class PixelforgeWebhookSignature
{
    private const TOLERANCE_SECONDS = 300;

    public function isValid(string $header, string $body): bool
    {
        $secret = config('services.pixelforge.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $parts = explode(',', $header);

        if (count($parts) !== 2) {
            return false;
        }

        $values = [];

        foreach ($parts as $part) {
            if (! preg_match('/\A(t|v1)=([a-fA-F0-9]+)\z/', trim($part), $match) || isset($values[$match[1]])) {
                return false;
            }

            $values[$match[1]] = $match[2];
        }

        $timestamp = $values['t'] ?? '';
        $signature = $values['v1'] ?? '';

        if (! preg_match('/\A[1-9][0-9]{0,18}\z/', $timestamp) || strlen($signature) !== 64) {
            return false;
        }

        $seconds = filter_var($timestamp, FILTER_VALIDATE_INT);
        $now = now()->getTimestamp();

        if ($seconds === false || $seconds < $now - self::TOLERANCE_SECONDS || $seconds > $now + self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), strtolower($signature));
    }
}
