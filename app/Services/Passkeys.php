<?php

namespace App\Services;

use App\Models\Passkey;
use App\Models\User;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys and security keys (WebAuthn) on top of web-auth/webauthn-lib. The relying party and the one
 * accepted origin come from APP_URL, never from the request's Host header, so a forged Host cannot
 * widen what is accepted. Attestation is not requested ("none"): we need a key we can verify later,
 * not proof of the device model.
 */
class Passkeys
{
    private SerializerInterface $serializer;

    public function __construct()
    {
        $this->serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport()])
        ))->create();
    }

    public function newChallenge(): string
    {
        return Base64UrlSafe::encodeUnpadded(random_bytes(32));
    }

    public function rpId(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    public function origin(): string
    {
        $u = parse_url((string) config('app.url'));

        return ($u['scheme'] ?? 'https').'://'.($u['host'] ?? '').(isset($u['port']) ? ':'.$u['port'] : '');
    }

    /** JSON for navigator.credentials.create() */
    public function creationOptionsJson(User $user, string $challenge): string
    {
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(config('app.name'), $this->rpId()),
            PublicKeyCredentialUserEntity::create($user->email, $user->webauthnHandle(), $user->name ?: $user->email),
            Base64UrlSafe::decodeNoPadding($challenge),
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $this->descriptors($user),
            timeout: 120_000,
        );

        return $this->toJson($options);
    }

    /** JSON for navigator.credentials.get() */
    public function requestOptionsJson(User $user, string $challenge): string
    {
        return $this->toJson($this->requestOptions($user, $challenge));
    }

    /** Verifies a new credential and stores it. */
    public function register(User $user, string $name, string $credentialJson, string $challenge): Passkey
    {
        try {
            $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
            $response = $credential->response;
            if (! $response instanceof AuthenticatorAttestationResponse) {
                throw new RuntimeException('Not an attestation response.');
            }

            $options = PublicKeyCredentialCreationOptions::create(
                PublicKeyCredentialRpEntity::create(config('app.name'), $this->rpId()),
                PublicKeyCredentialUserEntity::create($user->email, $user->webauthnHandle(), $user->name ?: $user->email),
                Base64UrlSafe::decodeNoPadding($challenge),
                [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            );

            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies()->creationCeremony())
                ->check($response, $options, $this->rpId());
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException('That passkey could not be verified. Try again, or use another device.');
        }

        $id = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);
        if (Passkey::where('credential_id', $id)->exists()) {
            throw new RuntimeException('That passkey is already registered.');
        }

        return $user->passkeys()->create(['name' => $name, 'credential_id' => $id, 'record' => $this->serializer->serialize($record, 'json')]);
    }

    /** Verifies a sign-in assertion for this person; returns the passkey that was used. */
    public function assert(User $user, string $credentialJson, string $challenge): Passkey
    {
        try {
            $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
            $response = $credential->response;
            if (! $response instanceof AuthenticatorAssertionResponse) {
                throw new RuntimeException('Not an assertion response.');
            }

            // Only this person's own passkeys can answer; the credential id alone never selects an account.
            $passkey = $user->passkeys()->where('credential_id', Base64UrlSafe::encodeUnpadded($credential->rawId))->firstOrFail();
            $record = $this->serializer->deserialize($passkey->record, CredentialRecord::class, 'json');

            $updated = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
                ->check($record, $response, $this->requestOptions($user, $challenge), $this->rpId(), $record->userHandle);
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException('That passkey could not be verified.');
        }

        // Keep the new signature counter: a cloned key shows up as a counter that goes backwards.
        $passkey->forceFill(['record' => $this->serializer->serialize($updated, 'json'), 'last_used_at' => now()])->save();

        return $passkey;
    }

    private function requestOptions(User $user, string $challenge): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            Base64UrlSafe::decodeNoPadding($challenge),
            $this->rpId(),
            $this->descriptors($user),
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: 120_000,
        );
    }

    /** @return PublicKeyCredentialDescriptor[] */
    private function descriptors(User $user): array
    {
        return $user->passkeys()->pluck('record')->map(
            fn ($json) => $this->serializer->deserialize($json, CredentialRecord::class, 'json')->getPublicKeyCredentialDescriptor()
        )->all();
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin()]);
        // Browsers allow passkeys on plain http only for localhost (development).
        $factory->setSecuredRelyingPartyId(['localhost']);

        return $factory;
    }

    private function toJson(object $options): string
    {
        return $this->serializer->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);
    }
}
