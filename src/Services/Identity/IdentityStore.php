<?php
/**
 * Slate — IdentityStore (Phase 2A concrete service).
 *
 * The portal-login surface that replaces the customers/customer_auth_tokens auth
 * data (identity doc §4). It resolves a login → the Contact behind it, registers/
 * promotes, verifies credentials (bcrypt + auto-rehash), and mints/consumes
 * single-use SHA-256 tokens — the same defenses the shell has today, moved behind
 * one seam.
 *
 * SCOPE (2A/B3): the pure identity engine — additive, nothing calls it yet. The
 * throttled `attemptLogin` + session + the `Auth::` forwarders are B4 (the auth
 * cutover, gated on review). Secrets never leave this class (the Identity model
 * carries no password_hash).
 *
 * Concrete class; Contracts\Identity interface deferred to Phase 3 (design A4).
 * Layer: Services\Identity (uses Data + Tenancy + Domain).
 */

declare(strict_types=1);

namespace Slate\Services\Identity;

use Slate\Data\Database;
use Slate\Data\QueryBuilder;
use Slate\Domain\Identity\Contact;
use Slate\Domain\Identity\EmailAddress;
use Slate\Domain\Identity\Identity;
use Slate\Tenancy\TenantContext;

final class IdentityStore
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ContactRepository $contacts,
    ) {}

    // ── Resolve ───────────────────────────────────────────────

    public function findByCredential(string $provider, string $credentialRef): ?Identity
    {
        $row = $this->scoped('identities')
            ->where('provider', $provider)
            ->where('credential_ref', $this->normalizeCredential($provider, $credentialRef))
            ->first();
        return $row === null ? null : Identity::fromRow($row);
    }

    /** The Contact this login IS (identity doc §4). */
    public function contactFor(int $identityId): ?Contact
    {
        $row = $this->scoped('identities')->select('contact_id')->where('id', $identityId)->first();
        return $row === null ? null : $this->contacts->find((int) $row['contact_id']);
    }

    // ── Register / promote (same contact_id, additive) ────────

    /**
     * Attach a password login to an existing Contact — registration and guest
     * promotion are the same additive operation (identity doc §4): the Contact,
     * and all its history, keep the same id.
     */
    public function register(
        int $contactId,
        string $credentialRef,
        string $plainPassword,
        bool $emailVerified = false,
        string $provider = Identity::PROVIDER_PASSWORD,
    ): Identity {
        $id = QueryBuilder::table('identities')->insert([
            'contact_id'     => $contactId,
            'tenant_id'      => $this->tenants->id(),
            'provider'       => $provider,
            'credential_ref' => $this->normalizeCredential($provider, $credentialRef),
            'password_hash'  => password_hash($plainPassword, PASSWORD_DEFAULT),
            'email_verified' => $emailVerified ? 1 : 0,
            'status'         => Identity::STATUS_ACTIVE,
        ]);
        $identity = $this->findById($id);
        if ($identity === null) {
            throw new \RuntimeException('IdentityStore::register failed to read back the new identity.');
        }
        return $identity;
    }

    // ── Authenticate (pure verify; throttle/session are the Auth/B4 layer) ──

    /**
     * Verify a credential and, on success, auto-rehash + stamp last_login_at.
     * Returns the Identity or null. Timing-equalisation, per-IP throttling, and
     * session creation are applied by the caller (Auth) in B4 — this is the crypto
     * core only.
     */
    public function authenticate(string $provider, string $credentialRef, string $secret): ?Identity
    {
        $cred = $this->normalizeCredential($provider, $credentialRef);
        $row = $this->scoped('identities')->where('provider', $provider)->where('credential_ref', $cred)->first();

        if ($row === null || ($row['status'] ?? '') !== Identity::STATUS_ACTIVE || empty($row['password_hash'])) {
            return null;
        }
        if (!password_verify($secret, (string) $row['password_hash'])) {
            return null;
        }
        $id = (int) $row['id'];
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $this->scoped('identities')->where('id', $id)->update(['password_hash' => password_hash($secret, PASSWORD_DEFAULT)]);
        }
        $this->scoped('identities')->where('id', $id)->update(['last_login_at' => date('Y-m-d H:i:s')]);
        return $this->findById($id);
    }

    // ── Tokens (single-use, SHA-256 hashed, TTL'd) ────────────

    /** Mint a token, store its SHA-256, return the plaintext for the email link. */
    public function issueToken(int $identityId, string $purpose, int $ttlSeconds): string
    {
        // Supersede any outstanding token of the same purpose.
        Database::query(
            'UPDATE identity_tokens SET used_at = NOW() WHERE identity_id = ? AND purpose = ? AND used_at IS NULL',
            [$identityId, $purpose]
        );
        $plaintext = bin2hex(random_bytes(32));
        QueryBuilder::table('identity_tokens')->insert([
            'tenant_id'  => $this->tenants->id(),
            'identity_id'=> $identityId,
            'purpose'    => $purpose,
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);
        return $plaintext;
    }

    /** Validate + burn a token. Returns the linked Identity, or null if invalid/expired/used. */
    public function consumeToken(string $rawToken, string $purpose): ?Identity
    {
        if ($rawToken === '' || strlen($rawToken) > 128) {
            return null;
        }
        $row = Database::row(
            'SELECT * FROM identity_tokens
              WHERE token_hash = ? AND purpose = ? AND tenant_id = ?
                AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            [hash('sha256', $rawToken), $purpose, $this->tenants->id()]
        );
        if ($row === null) {
            return null;
        }
        Database::query('UPDATE identity_tokens SET used_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $row['id']]);
        return $this->findById((int) $row['identity_id']);
    }

    // ── State ─────────────────────────────────────────────────

    public function markEmailVerified(int $identityId): void
    {
        $this->scoped('identities')->where('id', $identityId)->update(['email_verified' => 1]);
    }

    public function suspend(int $identityId): void
    {
        $this->scoped('identities')->where('id', $identityId)->update(['status' => Identity::STATUS_SUSPENDED]);
    }

    // ── Internal ──────────────────────────────────────────────

    private function findById(int $identityId): ?Identity
    {
        $row = $this->scoped('identities')->where('id', $identityId)->first();
        return $row === null ? null : Identity::fromRow($row);
    }

    private function normalizeCredential(string $provider, string $ref): string
    {
        return $provider === Identity::PROVIDER_PASSWORD ? EmailAddress::normalize($ref) : trim($ref);
    }

    private function scoped(string $table): QueryBuilder
    {
        return QueryBuilder::table($table)->where('tenant_id', $this->tenants->id());
    }
}
