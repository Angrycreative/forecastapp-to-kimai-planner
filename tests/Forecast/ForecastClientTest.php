<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Tests\Forecast;

use KimaiPlugin\ForecastImportBundle\Forecast\ForecastApiException;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastClient;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastCredentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ForecastClient::class)]
#[CoversClass(ForecastCredentials::class)]
final class ForecastClientTest extends TestCase
{
    public function testReadsEverythingAndFetchesAssignmentsInChunksOnce(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['normalized_headers']];
            $path = parse_url($url, \PHP_URL_PATH);
            $body = match ($path) {
                '/whoami' => ['current_user' => ['id' => 1]],
                '/people' => ['people' => [['id' => 1, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.c', 'archived' => false]]],
                '/placeholders' => ['placeholders' => []],
                '/clients' => ['clients' => []],
                '/projects' => ['projects' => [['id' => 10, 'name' => 'P', 'client_id' => null, 'tags' => [], 'archived' => false]]],
                // the same long assignment comes back in every chunk
                '/assignments' => ['assignments' => [['id' => 7, 'start_date' => '2026-01-01', 'end_date' => '2028-01-01', 'allocation' => 3600, 'project_id' => 10, 'person_id' => 1, 'placeholder_id' => null, 'updated_at' => 'x']]],
                default => [],
            };

            return new MockResponse((string) json_encode($body));
        });

        $data = (new ForecastClient($client))->fetch(new ForecastCredentials('123', 'secret'), new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2027-11-08'));

        self::assertCount(1, $data->assignments);
        $assignmentCalls = array_values(array_filter($requests, static fn (array $r): bool => str_contains($r[1], '/assignments')));
        self::assertCount(3, $assignmentCalls, 'a year in chunks of 180 days');
        self::assertStringContainsString('start_date=2026-11-09', $assignmentCalls[0][1]);
        self::assertStringContainsString('end_date=2027-11-08', $assignmentCalls[2][1]);
        foreach ($requests as [$method, , $headers]) {
            self::assertSame('GET', $method);
            self::assertSame(['Authorization: Bearer secret'], $headers['authorization']);
            self::assertSame(['Forecast-Account-ID: 123'], $headers['forecast-account-id']);
        }
    }

    public function testExplainsRefusedCredentials(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 401]));

        $this->expectException(ForecastApiException::class);
        $this->expectExceptionMessage('refused the token');
        (new ForecastClient($client))->fetch(new ForecastCredentials('123', 'wrong'), new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2026-11-10'));
    }

    public function testStopsWhenTheAnswerChangedShape(): void
    {
        $client = new MockHttpClient(new MockResponse('{"me": {}}'));

        $this->expectException(ForecastApiException::class);
        $this->expectExceptionMessage('has no "current_user"');
        (new ForecastClient($client))->fetch(new ForecastCredentials('123', 'x'), new \DateTimeImmutable('2026-11-09'), new \DateTimeImmutable('2026-11-10'));
    }

    public function testCredentialsNeverShowTheToken(): void
    {
        $credentials = new ForecastCredentials('123', 'secret');

        self::assertStringNotContainsString('secret', print_r($credentials, true));
        self::assertStringNotContainsString('secret', var_export($credentials->__debugInfo(), true));
        $this->expectException(\LogicException::class);
        serialize($credentials);
    }

    public function testRejectsAnAccountIdThatIsNotANumber(): void
    {
        $this->expectException(ForecastApiException::class);
        new ForecastCredentials('forecastapp.com/123', 'x');
    }
}
