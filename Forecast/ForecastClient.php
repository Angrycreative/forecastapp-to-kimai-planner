<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Forecast;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a Forecast account. Read only: it never sends anything but GET requests.
 *
 * Forecast has no official public API. These are the endpoints its own web app calls, as used by the community
 * libraries xvilo/harvest-forecast and forecast-promise: a Harvest personal access token as Bearer token plus the
 * Forecast-Account-ID header.
 */
class ForecastClient
{
    public const BASE_URL = 'https://api.forecastapp.com/';

    /** Days per assignments request; Forecast requires a range and long ranges make large responses. */
    private const ASSIGNMENT_CHUNK_DAYS = 180;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function fetch(ForecastCredentials $credentials, \DateTimeImmutable $begin, \DateTimeImmutable $end): ForecastData
    {
        if ($end < $begin) {
            throw new ForecastApiException('The end of the import range lies before its start.');
        }

        // a cheap call first, so wrong credentials fail with a clear message before anything large is fetched
        $this->get($credentials, 'whoami', [], 'current_user');

        $collections = [
            'people' => $this->get($credentials, 'people', [], 'people'),
            'placeholders' => $this->get($credentials, 'placeholders', [], 'placeholders'),
            'clients' => $this->get($credentials, 'clients', [], 'clients'),
            'projects' => $this->get($credentials, 'projects', [], 'projects'),
            'assignments' => $this->assignments($credentials, $begin, $end),
        ];

        return ForecastData::fromForecast($credentials->accountId, $begin, $end, new \DateTimeImmutable(), $collections);
    }

    /**
     * Assignments overlapping the range, fetched in chunks. An assignment spanning two chunks comes back twice and is
     * kept once.
     *
     * @return list<array<string, mixed>>
     */
    private function assignments(ForecastCredentials $credentials, \DateTimeImmutable $begin, \DateTimeImmutable $end): array
    {
        $byId = [];
        for ($from = $begin; $from <= $end; $from = $to->modify('+1 day')) {
            $to = min($end, $from->modify('+' . (self::ASSIGNMENT_CHUNK_DAYS - 1) . ' days'));
            $chunk = $this->get($credentials, 'assignments', [
                'start_date' => $from->format('Y-m-d'),
                'end_date' => $to->format('Y-m-d'),
            ], 'assignments');
            foreach ($chunk as $assignment) {
                if (\is_array($assignment) && isset($assignment['id'])) {
                    $byId[(int) $assignment['id']] = $assignment;
                }
            }
        }

        return array_values($byId);
    }

    /**
     * @param array<string, string> $query
     * @return array<mixed>
     */
    private function get(ForecastCredentials $credentials, string $path, array $query, string $key): array
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . $path, [
                'query' => $query,
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials->token(),
                    'Forecast-Account-ID' => $credentials->accountId,
                    'User-Agent' => 'Kimai ForecastImportBundle (https://github.com/Angrycreative/forecastapp-to-kimai-planner)',
                    'Accept' => 'application/json',
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new ForecastApiException('Forecast refused the token or account ID. Check that the token belongs to someone with access to this Forecast account.');
            }
            if ($status === 404 && $path === 'whoami') {
                throw new ForecastApiException('Forecast does not know this account ID.');
            }
            if ($status !== 200) {
                throw new ForecastApiException(\sprintf('Forecast answered "%s" with HTTP %d.', $path, $status));
            }
            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new ForecastApiException(\sprintf('Could not reach Forecast (%s). The Kimai server needs outbound HTTPS to api.forecastapp.com.', $exception->getMessage()), 0, $exception);
        }

        if (!\array_key_exists($key, $data) || !\is_array($data[$key])) {
            throw new ForecastApiException(\sprintf('Forecast\'s answer to "%s" has no "%s". Its API may have changed; nothing was imported.', $path, $key));
        }

        return $data[$key];
    }
}
