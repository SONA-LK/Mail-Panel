<?php

namespace App\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** Time-based one-time passwords (RFC 6238): 6 digits, 30 seconds, SHA-1, the setting every authenticator app supports. */
class Totp
{
    public const PERIOD = 30;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $n = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($n % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public function currentStep(?int $now = null): int
    {
        return intdiv($now ?? time(), self::PERIOD);
    }

    /**
     * Accepts the current 30-second window and one either side (clock drift). A step that was already used,
     * or an older one, is refused so a code cannot be replayed. Returns the step that matched, or null.
     */
    public function verify(string $secret, string $input, ?int $lastUsedStep = null, ?int $now = null): ?int
    {
        $input = preg_replace('/\s+/', '', $input);
        if (! preg_match('/^\d{6}$/', (string) $input)) {
            return null;
        }

        $current = $this->currentStep($now);
        $matched = null;
        foreach ([-1, 0, 1] as $drift) {
            // Compare every window so timing does not reveal which one was close.
            if (hash_equals($this->code($secret, $current + $drift), $input) && ($lastUsedStep === null || $current + $drift > $lastUsedStep)) {
                $matched = $current + $drift;
            }
        }

        return $matched;
    }

    public function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period='.self::PERIOD;
    }

    /** The QR code as an image data URI. An <img> keeps it under the strict CSP (an inline <svg><style> would be blocked). */
    public function qrDataUri(string $uri): string
    {
        return (new QRCode(new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
        ])))->render($uri);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($chunk) => self::ALPHABET[bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    private function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($byte) => chr(bindec($byte)), str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8)));
    }
}
