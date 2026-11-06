<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Import;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Kimai users and projects the import can point at. Its cache is cleared between requests, like Doctrine's.
 */
class KimaiCatalogue implements ResetInterface
{
    /** @var array<string, User>|null */
    private ?array $usersByEmail = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * The user with this email address, compared without regard to case.
     */
    public function userByEmail(?string $email): ?User
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        if ($this->usersByEmail === null) {
            $this->usersByEmail = [];
            foreach ($this->entityManager->getRepository(User::class)->findAll() as $user) {
                if ($user->getEmail() !== null) {
                    $this->usersByEmail[mb_strtolower(trim($user->getEmail()))] = $user;
                }
            }
        }

        return $this->usersByEmail[mb_strtolower(trim($email))] ?? null;
    }

    /**
     * Visible projects of visible customers, the ones worth suggesting.
     *
     * @return list<ProjectCandidate>
     */
    public function projectCandidates(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.id AS projectId', 'p.name AS projectName', 'c.id AS customerId', 'c.name AS customerName')
            ->from(Project::class, 'p')
            ->join('p.customer', 'c')
            ->where('p.visible = true')
            ->andWhere('c.visible = true')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): ProjectCandidate => new ProjectCandidate((int) $row['customerId'], (string) $row['customerName'], (int) $row['projectId'], (string) $row['projectName']),
            $rows
        );
    }

    public function reset(): void
    {
        $this->usersByEmail = null;
    }

    public function project(int $id): ?Project
    {
        return $this->entityManager->find(Project::class, $id);
    }
}
