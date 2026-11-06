<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

/**
 * Suggests the Kimai project closest to a Forecast client and project. Names drift apart between tools ("Psykologpartners
 * / Psykologpartners Marketing" in Forecast, "Psykologpartners W & W AB / Psykologpartners Marketing Services" in
 * Kimai), so exact matching finds little: this scores word overlap and spelling similarity of both names instead.
 *
 * Words count by how rare they are among Kimai's customers and projects: "Marketing" or "Support" appear everywhere and
 * say little, a company name says a lot. A suggestion is only a starting point; the person running the import
 * confirms or changes it.
 */
final class ProjectSuggester
{
    /** Below this a suggestion is more likely wrong than right, so none is made. */
    public const MINIMUM_SCORE = 0.45;

    /** From this score a suggestion is good enough to be accepted in bulk. */
    public const CONFIDENT_SCORE = 0.8;

    /** Project names less alike than this never match, however well the customer does. */
    private const PROJECT_FLOOR = 0.4;

    /** Words that say nothing about which company or project is meant. */
    private const NOISE = ['ab', 'aps', 'as', 'asa', 'bv', 'co', 'gmbh', 'hb', 'inc', 'kb', 'llc', 'ltd', 'limited', 'oy', 'plc', 'the', 'och', 'and', 'of', 'i'];

    /** @var list<array{0: ProjectCandidate, 1: array{0: string, 1: list<string>}, 2: array{0: string, 1: list<string>}, 3: array{0: string, 1: list<string>}}> */
    private array $candidates = [];

    /** @var array<string, float> weight of each word known to Kimai */
    private array $weights = [];

    private float $unknownWeight;

    /**
     * @param iterable<ProjectCandidate> $candidates
     */
    public function __construct(iterable $candidates)
    {
        $frequency = [];
        foreach ($candidates as $candidate) {
            $both = self::normalise($candidate->customerName . ' ' . $candidate->projectName);
            $this->candidates[] = [
                $candidate,
                self::normalise($candidate->customerName),
                self::normalise($candidate->projectName),
                $both,
            ];
            foreach ($both[1] as $word) {
                $frequency[$word] = ($frequency[$word] ?? 0) + 1;
            }
        }

        $total = max(1, \count($this->candidates));
        foreach ($frequency as $word => $count) {
            $this->weights[$word] = log(1 + $total / $count);
        }
        $this->unknownWeight = log(1 + $total);
    }

    /**
     * Two readings are scored and the better one counts:
     *
     * - the Forecast client is the Kimai customer, and the project names match once the customer's own words are left
     *   out of both ("Psykologpartners / Psykologpartners Marketing" against "Psykologpartners W & W AB /
     *   Psykologpartners Marketing Services"). A matching customer alone is not enough: the projects must match too;
     * - the Forecast client is an agency or team and its project names the end customer ("Cyntora / Ankarsrum
     *   Marketing" against "Ankarsrum AB / Marketing Services").
     *
     * @return list<array{candidate: ProjectCandidate, score: float}> the best matches first, at most $limit
     */
    public function suggest(?string $forecastClient, string $forecastProject, int $limit = 3): array
    {
        $client = self::normalise((string) $forecastClient);
        $project = self::normalise($forecastProject);
        $projectWithoutClient = self::without($project, $client);

        $scored = [];
        foreach ($this->candidates as [$candidate, $kimaiCustomer, $kimaiProject, $kimaiBoth]) {
            $projectScore = $this->similarity($projectWithoutClient, self::without($kimaiProject, $kimaiCustomer));
            $direct = 0.0;
            if ($projectScore >= self::PROJECT_FLOOR) {
                $direct = $client[0] === '' ? $projectScore : 0.35 * $this->similarity($client, $kimaiCustomer) + 0.65 * $projectScore;
            }
            $endCustomer = 0.9 * $this->similarity($project, $kimaiBoth);
            $score = max($direct, $endCustomer);
            if ($score >= self::MINIMUM_SCORE) {
                $scored[] = ['candidate' => $candidate, 'score' => round($score, 3)];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['candidate']->projectName, $b['candidate']->projectName));

        return \array_slice($scored, 0, $limit);
    }

    /**
     * @return array{0: string, 1: list<string>} the cleaned name and its distinct words
     */
    public static function normalise(string $name): array
    {
        $name = mb_strtolower($name);
        $name = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
        $words = array_values(array_unique(array_filter(
            explode(' ', $name),
            static fn (string $word): bool => $word !== '' && !\in_array($word, self::NOISE, true)
        )));

        return [implode(' ', $words), $words];
    }

    /**
     * How alike the Forecast name ($forecast) is to the Kimai name ($kimai), from 0 to 1: the share of weighted words
     * they have in common, how much of the Forecast name is found in Kimai's, and plain spelling similarity.
     *
     * @param array{0: string, 1: list<string>} $forecast
     * @param array{0: string, 1: list<string>} $kimai
     */
    public function similarity(array $forecast, array $kimai): float
    {
        if ($forecast[0] === '' || $kimai[0] === '') {
            return 0.0;
        }
        if ($forecast[0] === $kimai[0]) {
            return 1.0;
        }

        $common = $this->weight(array_intersect($forecast[1], $kimai[1]));
        $jaccard = $common / $this->weight(array_unique(array_merge($forecast[1], $kimai[1])));
        $found = $common / $this->weight($forecast[1]);
        similar_text($forecast[0], $kimai[0], $percent);

        return 0.35 * $jaccard + 0.35 * $found + 0.3 * ($percent / 100);
    }

    /**
     * @param array<string> $words
     */
    private function weight(array $words): float
    {
        $sum = 0.0;
        foreach ($words as $word) {
            $sum += $this->weights[$word] ?? $this->unknownWeight;
        }

        return max($sum, 1e-9);
    }

    /**
     * The name without the words of another one, or the name itself when nothing would be left.
     *
     * @param array{0: string, 1: list<string>} $name
     * @param array{0: string, 1: list<string>} $other
     * @return array{0: string, 1: list<string>}
     */
    private static function without(array $name, array $other): array
    {
        $words = array_values(array_diff($name[1], $other[1]));

        return $words === [] ? $name : [implode(' ', $words), $words];
    }
}
