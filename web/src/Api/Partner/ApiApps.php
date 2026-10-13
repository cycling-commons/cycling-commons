<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\Partner;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * The registered partner apps (docs/specs/public-api.md §8): only they may
 * write. An app's key is shown once, when it is issued, and only its
 * SHA-256 is kept, so a database read hands nobody a working key.
 *
 * Account layer, not dataset (docs/specs/public-api-personal-data-boundary.md
 * §1.3): the table holds a contact address and is never served.
 *
 * @api
 */
final readonly class ApiApps
{
    /** The scope that lets an app send contributions. */
    public const string SCOPE_WRITE = 'contributions:write';

    /** Every key starts with this, so a leaked one is recognisable in a scan. */
    private const string KEY_PREFIX = 'ccp_';

    public function __construct(private Connection $db)
    {
    }

    /**
     * Register an app and issue its key. The key is in the answer and
     * nowhere else.
     *
     * @param list<string> $scopes
     *
     * @return array{id: int, key: string}
     */
    public function register(string $name, string $contactEmail, array $scopes): array
    {
        $key = self::KEY_PREFIX.bin2hex(random_bytes(24));
        $id = (int) $this->db->fetchOne(
            "INSERT INTO api_app (name, contact_email, key_hash, key_prefix, scopes, status, created_at)
             VALUES (:name, :email, :hash, :prefix, CAST(:scopes AS jsonb), 'active', now())
             RETURNING id",
            [
                'name' => $name,
                'email' => $contactEmail,
                'hash' => hash('sha256', $key),
                'prefix' => substr($key, 0, 8),
                'scopes' => json_encode(array_values(array_unique($scopes)), \JSON_THROW_ON_ERROR),
            ],
        );

        return ['id' => $id, 'key' => $key];
    }

    /**
     * Replace an app's key; the old one stops working at once.
     *
     * @return string|null the new key, or null for an unknown app
     */
    public function rotate(int $id): ?string
    {
        $key = self::KEY_PREFIX.bin2hex(random_bytes(24));
        $n = $this->db->executeStatement(
            'UPDATE api_app SET key_hash = :hash, key_prefix = :prefix WHERE id = :id',
            ['hash' => hash('sha256', $key), 'prefix' => substr($key, 0, 8), 'id' => $id],
        );

        return $n > 0 ? $key : null;
    }

    /** @return bool whether an app with that id exists */
    public function setStatus(int $id, string $status): bool
    {
        if (!\in_array($status, ['active', 'suspended'], true)) {
            throw new \InvalidArgumentException('status must be active or suspended');
        }

        return $this->db->executeStatement('UPDATE api_app SET status = :s WHERE id = :id', ['s' => $status, 'id' => $id]) > 0;
    }

    /**
     * The active app whose key the request carries as
     * `Authorization: Bearer <key>`; null for no key, an unknown key or a
     * suspended app. Never `?key=`: a write key in a URL ends up in access
     * logs and browser histories.
     */
    public function fromRequest(Request $request): ?ApiApp
    {
        $header = (string) $request->headers->get('Authorization', '');
        $key = 1 === preg_match('~^Bearer\s+(\S+)$~i', $header, $m) ? $m[1] : null;
        if (null === $key || !str_starts_with($key, self::KEY_PREFIX) || \strlen($key) > 100) {
            return null;
        }

        /** @var array{id: int|string, name: string, scopes: string}|false $row */
        $row = $this->db->fetchAssociative(
            "SELECT id, name, scopes::text AS scopes FROM api_app WHERE key_hash = :hash AND status = 'active'",
            ['hash' => hash('sha256', $key)],
        );
        if (false === $row) {
            return null;
        }
        // At most one write a minute per app: enough to see which apps are in use.
        $this->db->executeStatement(
            "UPDATE api_app SET last_used_at = now() WHERE id = :id AND (last_used_at IS NULL OR last_used_at < now() - interval '1 minute')",
            ['id' => (int) $row['id']],
        );
        $scopes = json_decode($row['scopes'], true);

        return new ApiApp((int) $row['id'], $row['name'], \is_array($scopes) ? array_values(array_filter($scopes, is_string(...))) : []);
    }

    /**
     * Every app, for the console listing. Never the key.
     *
     * @return list<array{id: int, name: string, contact_email: string, key_prefix: string, scopes: string, status: string, created_at: string, last_used_at: string|null}>
     */
    public function all(): array
    {
        /** @var list<array{id: int, name: string, contact_email: string, key_prefix: string, scopes: string, status: string, created_at: string, last_used_at: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative('SELECT id, name, contact_email, key_prefix, scopes::text AS scopes, status, created_at, last_used_at FROM api_app ORDER BY id');

        return $rows;
    }
}
