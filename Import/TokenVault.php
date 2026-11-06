<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\ForecastImportBundle\Entity\SavedToken;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastApiException;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastCredentials;

/**
 * Keeps the Harvest token between imports when someone asks it to, so it need not be pasted in for every read.
 *
 * The token is encrypted with libsodium's secretbox (XSalsa20-Poly1305), under a key derived from the instance's
 * APP_SECRET, and decrypted only to read Forecast. A copy of the database alone does not reveal it; someone holding
 * both the database and the APP_SECRET can decrypt it, so clear it once the migration is done. Changing APP_SECRET
 * makes a saved token unreadable, which is treated as "no token saved".
 */
class TokenVault
{
    private const CONTEXT = 'kimai-forecast-import-token-v1';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    public function save(ForecastCredentials $credentials): void
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = base64_encode($nonce . sodium_crypto_secretbox($credentials->token(), $nonce, $this->key()));

        $this->entityManager->wrapInTransaction(function () use ($credentials, $encrypted): void {
            $this->entityManager->createQuery('DELETE FROM ' . SavedToken::class . ' t')->execute();
            $this->entityManager->persist(new SavedToken($credentials->accountId, $encrypted));
        });
    }

    /**
     * The saved account and token, or null when none is saved or it can no longer be decrypted.
     */
    public function load(): ?ForecastCredentials
    {
        $saved = $this->saved();
        if ($saved === null) {
            return null;
        }

        $raw = base64_decode($saved->getEncrypted(), true);
        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $token = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if ($token === false) {
            return null;
        }

        try {
            return new ForecastCredentials($saved->getAccountId(), $token);
        } catch (ForecastApiException) {
            return null;
        }
    }

    public function saved(): ?SavedToken
    {
        return $this->entityManager->getRepository(SavedToken::class)->findOneBy([], ['id' => 'DESC']);
    }

    public function clear(): void
    {
        $this->entityManager->createQuery('DELETE FROM ' . SavedToken::class . ' t')->execute();
    }

    private function key(): string
    {
        if ($this->secret === '') {
            throw new \RuntimeException('Kimai has no APP_SECRET, so a token cannot be saved safely.');
        }

        return sodium_crypto_generichash(self::CONTEXT, hash('sha256', $this->secret, true), \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
