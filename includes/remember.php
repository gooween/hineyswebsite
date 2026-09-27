<?php
// ============================================================
// Hiney's Eggs & Live Chicken Business
// File: includes/remember.php
//
// Shared "remember me" helpers, used by both index.php (issuing
// the token on login, auto-login on return visits) and logout.php
// (clearing the token so logout actually logs the user out).
//
// Requires a table:
//   CREATE TABLE remember_tokens (
//       id INT AUTO_INCREMENT PRIMARY KEY,
//       user_id INT NOT NULL,
//       selector VARCHAR(24) NOT NULL UNIQUE,
//       validator_hash CHAR(64) NOT NULL,
//       expires_at DATETIME NOT NULL,
//       FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
//   );
//
// Requires $conn (mysqli) from config/db.php to already be loaded
// by the including file.
// ============================================================

const REMEMBER_COOKIE   = 'remember_me';
const REMEMBER_DURATION = 60 * 60 * 24 * 30; // 30 days

function isHttpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function issueRememberToken(mysqli $conn, int $userId): void
{
    $selector      = bin2hex(random_bytes(9));
    $validator     = bin2hex(random_bytes(33));
    $validatorHash = hash('sha256', $validator);
    $expiresAt     = date('Y-m-d H:i:s', time() + REMEMBER_DURATION);

    // One active remember-token per user; drop any previous one first.
    $stmt = $conn->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('isss', $userId, $selector, $validatorHash, $expiresAt);
    $stmt->execute();
    $stmt->close();

    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + REMEMBER_DURATION,
        'path'     => '/',
        'secure'   => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearRememberToken(mysqli $conn): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $parts    = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
        $selector = $parts[0] ?? '';
        if ($selector !== '') {
            $stmt = $conn->prepare('DELETE FROM remember_tokens WHERE selector = ?');
            $stmt->bind_param('s', $selector);
            $stmt->execute();
            $stmt->close();
        }
    }

    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REMEMBER_COOKIE]);
}
