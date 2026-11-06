<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The Harvest token, kept only when someone chooses to, and only encrypted (see TokenVault). One at most.
 */
#[ORM\Table(name: 'kimai2_forecast_import_token')]
#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
class SavedToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'saved_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $savedAt;

    public function __construct(
        #[ORM\Column(name: 'account_id', type: Types::STRING, length: 32)]
        private string $accountId,
        #[ORM\Column(name: 'encrypted', type: Types::TEXT)]
        private string $encrypted,
    ) {
        $this->savedAt = new \DateTimeImmutable();
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getEncrypted(): string
    {
        return $this->encrypted;
    }

    public function getSavedAt(): \DateTimeImmutable
    {
        return $this->savedAt;
    }
}
