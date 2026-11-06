<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use KimaiPlugin\ForecastImportBundle\Entity\Mapping;
use KimaiPlugin\ForecastImportBundle\Forecast\ForecastData;
use KimaiPlugin\ForecastImportBundle\Repository\MappingRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The decisions behind an import. Saved decisions win; where there is none:
 *
 * - a person goes to the Kimai user with the same email address,
 * - a placeholder is skipped, because the planner has no placeholders,
 * - Forecast's "Time Off" project becomes time off,
 * - anything else stays unmapped until someone decides.
 */
class MappingService implements ResetInterface
{
    private ?ProjectSuggester $suggester = null;

    public function __construct(
        private readonly MappingRepository $repository,
        private readonly KimaiCatalogue $catalogue,
    ) {
    }

    public function mappingSet(ForecastData $data): MappingSet
    {
        $people = [];
        $placeholders = [];
        $projects = [];
        foreach ($this->personRows($data) as $row) {
            if ($row['target'] !== null) {
                if ($row['kind'] === Mapping::PERSON) {
                    $people[$row['id']] = $row['target'];
                } else {
                    $placeholders[$row['id']] = $row['target'];
                }
            }
        }
        foreach ($this->projectRows($data, false) as $row) {
            if ($row['target'] !== null) {
                $projects[$row['id']] = $row['target'];
            }
        }

        return new MappingSet($people, $placeholders, $projects, $this->tentativeTags());
    }

    /**
     * People and placeholders with assignments in the snapshot, most assignments first.
     *
     * @return list<array{kind: string, id: int, name: string, email: ?string, archived: bool, assignments: int, target: ?Target}>
     */
    public function personRows(ForecastData $data): array
    {
        $counts = $data->assignmentCounts();
        $planned = $data->plannedTotals();
        $savedPeople = $this->repository->findByKind(Mapping::PERSON);
        $savedPlaceholders = $this->repository->findByKind(Mapping::PLACEHOLDER);

        $rows = [];
        foreach ($counts['people'] as $id => $count) {
            $person = $data->people[$id] ?? null;
            $email = $person === null ? null : ($person['email'] === null ? null : (string) $person['email']);
            $target = isset($savedPeople[(string) $id]) ? $savedPeople[(string) $id]->toTarget() : null;
            if ($target === null && ($user = $this->catalogue->userByEmail($email)) !== null) {
                $target = Target::user($user, true);
            }
            $rows[] = [
                'kind' => Mapping::PERSON,
                'id' => $id,
                'name' => $data->personName($id),
                'email' => $email,
                'archived' => (bool) ($person['archived'] ?? false),
                'assignments' => $count,
                'planned' => $planned['people'][$id] ?? null,
                'target' => $target,
            ];
        }
        foreach ($counts['placeholders'] as $id => $count) {
            $placeholder = $data->placeholders[$id] ?? null;
            $saved = $savedPlaceholders[(string) $id] ?? null;
            $rows[] = [
                'kind' => Mapping::PLACEHOLDER,
                'id' => $id,
                'name' => (string) ($placeholder['name'] ?? '#' . $id),
                'email' => null,
                'archived' => (bool) ($placeholder['archived'] ?? false),
                'assignments' => $count,
                'planned' => $planned['placeholders'][$id] ?? null,
                'target' => $saved?->toTarget() ?? Target::skip(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['target'] !== null, $b['assignments']] <=> [$b['target'] !== null, $a['assignments']]);

        return $rows;
    }

    /**
     * Projects with assignments in the snapshot, unmapped ones first, then by number of assignments; with $only, just
     * that one, so a single row can be refreshed without scoring every other project.
     *
     * @return list<array{id: int, client: ?string, name: string, tags: list<string>, archived: bool, assignments: int, target: ?Target, suggestions: list<array{candidate: ProjectCandidate, score: float}>}>
     */
    public function projectRows(ForecastData $data, bool $withSuggestions = true, ?int $only = null): array
    {
        $counts = $data->assignmentCounts()['projects'];
        $planned = $data->plannedTotals()['projects'];
        $saved = $this->repository->findByKind(Mapping::PROJECT);

        $rows = [];
        foreach ($counts as $id => $count) {
            if ($only !== null && $id !== $only) {
                continue;
            }
            $project = $data->project($id);
            $client = $project === null || $project['client_id'] === null ? null : $data->clientName((int) $project['client_id']);
            $name = (string) ($project['name'] ?? '#' . $id);
            $target = isset($saved[(string) $id]) ? $saved[(string) $id]->toTarget() : null;
            if ($target === null && $data->isTimeOffProject($id)) {
                $target = Target::timeOff(true);
            }
            $rows[] = [
                'id' => $id,
                'client' => $client,
                'name' => $name,
                'tags' => array_values(array_map('strval', (array) ($project['tags'] ?? []))),
                'archived' => (bool) ($project['archived'] ?? false),
                'assignments' => $count,
                'planned' => $planned[$id] ?? null,
                'target' => $target,
                'suggestions' => $withSuggestions && $target === null ? $this->suggester()->suggest($client, $name) : [],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['target'] !== null, $b['assignments']] <=> [$b['target'] !== null, $a['assignments']]);

        return $rows;
    }

    public function save(string $kind, int $forecastId, Target $target): void
    {
        $mapping = $this->repository->findOrCreate($kind, (string) $forecastId);
        $mapping->apply($target);
        $this->repository->save($mapping);
    }

    /**
     * Saves the best suggestion of every unmapped project whose suggestion is at least confident.
     *
     * @param list<int>|null $only Forecast project ids to accept, or null for all confident ones
     * @param bool $save false only counts what would be accepted
     * @return int how many were (or would be) accepted
     */
    public function acceptSuggestions(ForecastData $data, ?array $only = null, float $minimum = ProjectSuggester::CONFIDENT_SCORE, bool $save = true): int
    {
        $mappings = [];
        foreach ($this->projectRows($data) as $row) {
            $best = $row['suggestions'][0] ?? null;
            if ($row['target'] !== null || $best === null || $best['score'] < $minimum) {
                continue;
            }
            if ($only !== null && !\in_array($row['id'], $only, true)) {
                continue;
            }
            $project = $this->catalogue->project($best['candidate']->projectId);
            if ($project === null || $project->getCustomer() === null) {
                continue;
            }
            $mapping = $this->repository->findOrCreate(Mapping::PROJECT, (string) $row['id']);
            $mapping->apply(Target::work($project->getCustomer(), $project));
            $mappings[] = $mapping;
        }
        if ($save) {
            $this->repository->save(...$mappings);
        }

        return \count($mappings);
    }

    public function forget(string $kind, int $forecastId): void
    {
        $existing = $this->repository->findByKind($kind)[(string) $forecastId] ?? null;
        if ($existing !== null) {
            $this->repository->remove($existing);
        }
    }

    /**
     * @return list<string>
     */
    public function tentativeTags(): array
    {
        $tags = [];
        foreach ($this->repository->findByKind(Mapping::TAG) as $key => $mapping) {
            if ($mapping->getAction() === Mapping::TENTATIVE) {
                $tags[] = (string) $key;
            }
        }

        return $tags;
    }

    /**
     * @param list<string> $tags
     */
    public function saveTentativeTags(array $tags): void
    {
        $mappings = [];
        $wanted = array_flip($tags);
        foreach ($this->repository->findByKind(Mapping::TAG) as $key => $mapping) {
            $mapping->markTentative(isset($wanted[(string) $key]));
            $mappings[] = $mapping;
            unset($wanted[(string) $key]);
        }
        foreach (array_keys($wanted) as $tag) {
            $mapping = new Mapping(Mapping::TAG, (string) $tag);
            $mapping->markTentative(true);
            $mappings[] = $mapping;
        }
        $this->repository->save(...$mappings);
    }

    public function reset(): void
    {
        $this->suggester = null;
    }

    private function suggester(): ProjectSuggester
    {
        return $this->suggester ??= new ProjectSuggester($this->catalogue->projectCandidates());
    }
}
