<?php

namespace App\Service;

class EncryptionService
{
    private const PREFIX = 'enc:';

    private string $key;

    public function __construct(string $encryptionKey)
    {
        $this->key = sodium_base642bin($encryptionKey, SODIUM_BASE64_VARIANT_ORIGINAL);

        if (strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException(
                sprintf('APP_ENCRYPTION_KEY must decode to %d bytes.', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
            );
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::PREFIX . sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function decrypt(string $value): string
    {
        if (!$this->isEncrypted($value)) {
            return $value;
        }

        $decoded = sodium_base642bin(
            substr($value, strlen(self::PREFIX)),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );

        $nonce      = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — wrong key or corrupted data.');
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }
}
