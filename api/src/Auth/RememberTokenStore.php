<?php
declare(strict_types=1);

namespace YouthSync\Auth;

final class RememberTokenStore
{
    public function __construct(private string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_file($path)) {
            file_put_contents($path, '{}', LOCK_EX);
        }
    }

    /**
     * @return array{user_id:int,organization_id:int,role_code:string,youth_id:int,expires_at:int}|null
     */
    public function findValid(string $plainToken): ?array
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '' || $plainToken === '1') {
            return null;
        }
        $hash = hash('sha256', $plainToken);
        $now = time();
        $changed = false;
        $match = null;

        $this->mutate(function (array $records) use ($hash, $now, &$changed, &$match): array {
            if (!isset($records[$hash]) || !is_array($records[$hash])) {
                return $records;
            }
            $row = $records[$hash];
            $expires = (int) ($row['expires_at'] ?? 0);
            if ($expires < $now) {
                unset($records[$hash]);
                $changed = true;
                return $records;
            }
            $match = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'organization_id' => (int) ($row['organization_id'] ?? 0),
                'role_code' => (string) ($row['role_code'] ?? ''),
                'youth_id' => (int) ($row['youth_id'] ?? 0),
                'expires_at' => $expires,
            ];
            $adminRemember = $match['role_code'] === AuthService::ROLE_SYSTEM_ADMIN && $match['organization_id'] === 0;
            $youthRemember = $match['role_code'] === AuthService::ROLE_YOUTH
                && $match['organization_id'] === 0
                && $match['youth_id'] > 0;
            if ($match['user_id'] < 1 || (!$adminRemember && !$youthRemember && $match['organization_id'] < 1)) {
                unset($records[$hash]);
                $changed = true;
                $match = null;
            }
            return $records;
        });

        return $match;
    }

    public function put(string $plainToken, int $userId, int $organizationId, string $roleCode, int $expiresAt, int $youthId = 0): void
    {
        $hash = hash('sha256', $plainToken);
        $this->mutate(function (array $records) use ($hash, $userId, $organizationId, $roleCode, $expiresAt, $youthId): array {
            $records[$hash] = [
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'role_code' => $roleCode,
                'youth_id' => $youthId,
                'expires_at' => $expiresAt,
            ];
            return $records;
        });
    }

    public function forgetPlain(string $plainToken): void
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return;
        }
        $hash = hash('sha256', $plainToken);
        $this->mutate(function (array $records) use ($hash): array {
            unset($records[$hash]);
            return $records;
        });
    }

    public function forgetUser(int $userId): void
    {
        $this->mutate(function (array $records) use ($userId): array {
            foreach ($records as $hash => $row) {
                if (is_array($row) && (int) ($row['user_id'] ?? 0) === $userId) {
                    unset($records[$hash]);
                }
            }
            return $records;
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function mutate(callable $mutator): void
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $records = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $records = $decoded;
                }
            }
            $next = $mutator($records);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($next, JSON_UNESCAPED_SLASHES) ?: '{}');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
