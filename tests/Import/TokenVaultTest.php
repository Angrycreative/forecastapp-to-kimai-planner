<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Tests\Import;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use KimaiPlugin\ForecastImportBundle\Entity\SavedToken;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastCredentials;
use KimaiPlugin\ForecastImportBundle\Import\TokenVault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenVault::class)]
final class TokenVaultTest extends TestCase
{
    private ?SavedToken $stored = null;

    public function testStoresTheTokenOnlyEncryptedAndReadsItBack(): void
    {
        $vault = $this->vault('app-secret');
        $vault->save(new ForecastCredentials('123456', 'pat.secret-token-value'));

        self::assertNotNull($this->stored);
        self::assertSame('123456', $this->stored->getAccountId());
        self::assertStringNotContainsString('secret-token-value', $this->stored->getEncrypted());
        self::assertStringNotContainsString('secret-token-value', (string) base64_decode($this->stored->getEncrypted(), true));

        $loaded = $vault->load();
        self::assertNotNull($loaded);
        self::assertSame('123456', $loaded->accountId);
        self::assertSame('pat.secret-token-value', $loaded->token());
    }

    public function testEncryptsDifferentlyEachTime(): void
    {
        $vault = $this->vault('app-secret');
        $vault->save(new ForecastCredentials('1', 'same'));
        $first = $this->stored?->getEncrypted();
        $vault->save(new ForecastCredentials('1', 'same'));

        self::assertNotSame($first, $this->stored?->getEncrypted());
    }

    public function testAnotherSecretCannotReadIt(): void
    {
        $this->vault('app-secret')->save(new ForecastCredentials('1', 'token'));

        self::assertNull($this->vault('changed-secret')->load());
    }

    public function testTamperedOrMissingDataReadsAsNoToken(): void
    {
        self::assertNull($this->vault('app-secret')->load());

        $this->stored = new SavedToken('1', base64_encode(str_repeat('x', 80)));
        self::assertNull($this->vault('app-secret')->load());
    }

    public function testRefusesToSaveWithoutASecret(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->vault('')->save(new ForecastCredentials('1', 'token'));
    }

    private function vault(string $secret): TokenVault
    {
        $query = $this->createMock(Query::class);
        $query->method('execute')->willReturnCallback(function (): int {
            $this->stored = null;

            return 1;
        });

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(fn (): ?SavedToken => $this->stored);

        $em = $this->createMock(EntityManager::class);
        $em->method('createQuery')->willReturn($query);
        $em->method('getRepository')->willReturn($repository);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof SavedToken) {
                $this->stored = $entity;
            }
        });
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $run): mixed => $run());

        return new TokenVault($em, $secret);
    }
}
