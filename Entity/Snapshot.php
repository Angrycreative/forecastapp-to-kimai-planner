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
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;

/**
 * The last data read from Forecast, kept so mapping and previewing need no token. Only one is kept: fetching again
 * replaces it. Holds no credentials.
 */
#[ORM\Table(name: 'kimai2_forecast_import_snapshot')]
#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
class Snapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'account_id', type: Types::STRING, length: 32)]
    private string $accountId;

    #[ORM\Column(name: 'fetched_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(name: 'payload', type: Types::TEXT, length: 4294967295)]
    private string $payload;

    public function __construct(ForecastData $data)
    {
        $this->accountId = $data->accountId;
        $this->fetchedAt = $data->fetchedAt;
        $this->payload = (string) json_encode($data->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getData(): ForecastData
    {
        return ForecastData::fromArray((array) json_decode($this->payload, true, 512, \JSON_THROW_ON_ERROR));
    }
}
