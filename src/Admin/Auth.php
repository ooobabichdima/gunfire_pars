<?php

declare(strict_types=1);

namespace App\Admin;

use App\Database;

final class Auth
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function isAuthenticated(): bool
    {
        return !empty($_SESSION['admin_authenticated']);
    }

    public function login(string $password): bool
    {
        $hash = $this->getPasswordHash();
        if ($hash === null) {
            return false;
        }

        if (password_verify($password, $hash)) {
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_login_time'] = time();
            return true;
        }

        return false;
    }

    public function logout(): void
    {
        unset($_SESSION['admin_authenticated'], $_SESSION['admin_login_time']);
    }

    public function hasPassword(): bool
    {
        return $this->getPasswordHash() !== null;
    }

    public function setPassword(string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('admin_password_hash', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$hash]
        );
    }

    private function getPasswordHash(): ?string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'admin_password_hash'");
        return $row ? $row['value'] : null;
    }
}
