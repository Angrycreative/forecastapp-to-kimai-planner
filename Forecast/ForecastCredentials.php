<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Forecast;

/**
 * The Forecast account and the Harvest personal access token that may read it. Lives only for one fetch: it is never
 * stored, serialised or logged.
 */
final class ForecastCredentials
{
    public function __construct(
        public readonly string $accountId,
        #[\SensitiveParameter]
        private readonly string $token,
    ) {
        if (preg_match('/^\d+$/', $accountId) !== 1) {
            throw new ForecastApiException('The Forecast account ID is the number in your Forecast address, forecastapp.com/<account ID>/.');
        }
        if (trim($token) === '') {
            throw new ForecastApiException('A Harvest personal access token is required.');
        }
    }

    public function token(): string
    {
        return $this->token;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['accountId' => $this->accountId, 'token' => '***'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Forecast credentials are never serialised.');
    }
}
