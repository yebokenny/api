<?php

namespace App\Support;

class IdentityPassword
{
    public static function verify(string $password, ?string $hashed): bool
    {
        if ($hashed === null || $hashed === '') {
            return false;
        }

        if (str_starts_with($hashed, '$2y$') || str_starts_with($hashed, '$2a$') || str_starts_with($hashed, '$2b$')) {
            return password_verify($password, $hashed);
        }

        $decoded = base64_decode($hashed, true);
        if ($decoded === false || strlen($decoded) < 13 || ord($decoded[0]) !== 0x01) {
            return false;
        }

        $prf = unpack('N', substr($decoded, 1, 4))[1];
        $iterations = unpack('N', substr($decoded, 5, 4))[1];
        $saltLength = unpack('N', substr($decoded, 9, 4))[1];
        if ($saltLength < 1 || 13 + $saltLength >= strlen($decoded)) {
            return false;
        }

        $salt = substr($decoded, 13, $saltLength);
        $subkey = substr($decoded, 13 + $saltLength);
        $algorithm = $prf === 2 ? 'sha512' : 'sha256';
        $computed = hash_pbkdf2($algorithm, $password, $salt, $iterations, strlen($subkey), true);

        return hash_equals($subkey, $computed);
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}
