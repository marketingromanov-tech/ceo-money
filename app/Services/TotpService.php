<?php

namespace App\Services;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) $secret .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        return $secret;
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) return false;
        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->at($secret, $counter + $offset), $code)) return true;
        }
        return false;
    }

    public function provisioningUri(string $secret, string $email): string
    {
        $label = rawurlencode('CEO Money:'.$email);
        return "otpauth://totp/{$label}?secret={$secret}&issuer=CEO%20Money&digits=6&period=30";
    }

    public function currentCode(string $secret, ?int $timestamp = null): string
    {
        return $this->at($secret, intdiv($timestamp ?? time(), 30));
    }

    private function at(string $secret, int $counter): string
    {
        $key = $this->decodeBase32($secret);
        $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function decodeBase32(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position !== false) $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $decoded .= chr(bindec($chunk));
        return $decoded;
    }
}
