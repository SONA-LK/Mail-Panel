<?php

namespace Tests\Support;

/** A pretend security key for tests: ES256 key pair, "none" attestation, real WebAuthn byte formats. */
class SoftAuthenticator
{
    public string $credentialId;

    public int $counter = 0;

    private $key;

    public function __construct(private string $rpId = 'localhost', private string $origin = 'http://localhost')
    {
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'config' => __DIR__.'/openssl.cnf']);
        $this->credentialId = random_bytes(32);
    }

    public function withOrigin(string $origin): static
    {
        $clone = clone $this;
        $clone->origin = $origin;

        return $clone;
    }

    /** Answer navigator.credentials.create() options (the JSON the server sent). */
    public function register(array $options): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = $this->cbor([1 => 2, 3 => -7, -1 => 1, -2 => new Bytes($ec['x']), -3 => new Bytes($ec['y'])]);

        $authData = hash('sha256', $this->rpId, true).chr(0x45).pack('N', 0)
            .str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$cose;

        $attestation = $this->cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new Bytes($authData)]);

        return [
            'id' => $this->b64u($this->credentialId),
            'rawId' => $this->b64u($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->b64u($this->clientData('webauthn.create', $options['challenge'])),
                'attestationObject' => $this->b64u($attestation),
            ],
        ];
    }

    /** Answer navigator.credentials.get() options. */
    public function assert(array $options): array
    {
        $this->counter++;
        $clientData = $this->clientData('webauthn.get', $options['challenge']);
        $authData = hash('sha256', $this->rpId, true).chr(0x05).pack('N', $this->counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => $this->b64u($this->credentialId),
            'rawId' => $this->b64u($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->b64u($clientData),
                'authenticatorData' => $this->b64u($authData),
                'signature' => $this->b64u($signature),
                'userHandle' => null,
            ],
        ];
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false]);
    }

    private function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Just enough CBOR: ints, text, byte strings, maps. */
    private function cbor(mixed $v): string
    {
        return match (true) {
            is_int($v) => $v >= 0 ? $this->head(0, $v) : $this->head(1, -1 - $v),
            $v instanceof Bytes => $this->head(2, strlen($v->data)).$v->data,
            is_string($v) => $this->head(3, strlen($v)).$v,
            is_array($v) => $this->head(5, count($v)).implode('', array_map(fn ($k, $x) => $this->cbor($k).$this->cbor($x), array_keys($v), $v)),
        };
    }

    private function head(int $major, int $n): string
    {
        return match (true) {
            $n < 24 => chr(($major << 5) | $n),
            $n < 256 => chr(($major << 5) | 24).chr($n),
            default => chr(($major << 5) | 25).pack('n', $n),
        };
    }
}

class Bytes
{
    public function __construct(public string $data)
    {
    }
}
