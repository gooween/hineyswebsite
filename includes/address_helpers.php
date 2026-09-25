<?php
// ============================================================
// HATCH — Address helpers (Shopee-style multi-address)
// File: includes/address_helpers.php
// Shared by profile.php and checkout.php. Requires $conn.
// ============================================================

if (!function_exists('getUserAddresses')) {
    /** All of a user's saved addresses, default first. */
    function getUserAddresses(mysqli $conn, int $userId): array
    {
        $rows = [];
        $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id ASC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('getDefaultAddress')) {
    /** The user's default address (or their first, or null). */
    function getDefaultAddress(mysqli $conn, int $userId): ?array
    {
        $all = getUserAddresses($conn, $userId);
        return $all[0] ?? null; // ordered default-first
    }
}

if (!function_exists('addUserAddress')) {
    /** Insert an address. If it's the user's first, force default. */
    function addUserAddress(mysqli $conn, int $userId, array $a): int
    {
        $existing = getUserAddresses($conn, $userId);
        $isDefault = (empty($existing) || !empty($a['is_default'])) ? 1 : 0;
        if ($isDefault) {
            $conn->query("UPDATE user_addresses SET is_default=0 WHERE user_id={$userId}");
        }
        $stmt = $conn->prepare("INSERT INTO user_addresses
            (user_id, label, recipient_name, phone, municipality, barangay, street_address, landmark, is_default)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param(
            'isssssssi',
            $userId,
            $a['label'],
            $a['recipient_name'],
            $a['phone'],
            $a['municipality'],
            $a['barangay'],
            $a['street_address'],
            $a['landmark'],
            $isDefault
        );
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('updateUserAddress')) {
    /** Update one address (must belong to the user). */
    function updateUserAddress(mysqli $conn, int $userId, int $addrId, array $a): void
    {
        $stmt = $conn->prepare("UPDATE user_addresses SET
            label=?, recipient_name=?, phone=?, municipality=?, barangay=?, street_address=?, landmark=?
            WHERE id=? AND user_id=?");
        $stmt->bind_param(
            'sssssssii',
            $a['label'],
            $a['recipient_name'],
            $a['phone'],
            $a['municipality'],
            $a['barangay'],
            $a['street_address'],
            $a['landmark'],
            $addrId,
            $userId
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('deleteUserAddress')) {
    /** Delete an address; if it was default, promote another to default. */
    function deleteUserAddress(mysqli $conn, int $userId, int $addrId): void
    {
        $wasDefault = false;
        $chk = $conn->query("SELECT is_default FROM user_addresses WHERE id={$addrId} AND user_id={$userId} LIMIT 1");
        if ($chk && $row = $chk->fetch_assoc()) $wasDefault = (int)$row['is_default'] === 1;

        $conn->query("DELETE FROM user_addresses WHERE id={$addrId} AND user_id={$userId}");

        if ($wasDefault) {
            // promote the next-oldest address to default
            $next = $conn->query("SELECT id FROM user_addresses WHERE user_id={$userId} ORDER BY id ASC LIMIT 1");
            if ($next && $row = $next->fetch_assoc()) {
                $nid = (int)$row['id'];
                $conn->query("UPDATE user_addresses SET is_default=1 WHERE id={$nid}");
            }
        }
    }
}

if (!function_exists('setDefaultAddress')) {
    /** Make one address the default (un-defaults the rest). */
    function setDefaultAddress(mysqli $conn, int $userId, int $addrId): void
    {
        $conn->query("UPDATE user_addresses SET is_default=0 WHERE user_id={$userId}");
        $conn->query("UPDATE user_addresses SET is_default=1 WHERE id={$addrId} AND user_id={$userId}");
    }
}

if (!function_exists('formatAddress')) {
    /** One-line address string. */
    function formatAddress(array $a): string
    {
        $parts = array_filter([
            $a['street_address'] ?? '',
            $a['barangay'] ?? '',
            $a['municipality'] ?? '',
            'Bohol'
        ], fn($p) => trim((string)$p) !== '');
        return implode(', ', $parts);
    }
}
