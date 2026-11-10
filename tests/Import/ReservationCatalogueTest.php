<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Tests\Import;

use KimaiPlugin\ForecastImportBundle\Entity\Mapping;
use KimaiPlugin\ForecastImportBundle\Import\PlannerCapabilities;
use KimaiPlugin\ForecastImportBundle\Import\ReservationCatalogue;
use KimaiPlugin\ForecastImportBundle\Import\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReservationCatalogue::class)]
#[CoversClass(Mapping::class)]
final class ReservationCatalogueTest extends TestCase
{
    private static function reservation(int $id, string $name): object
    {
        return new class($id, $name) {
            public function __construct(private readonly int $id, private readonly string $name)
            {
            }

            public function getId(): int
            {
                return $this->id;
            }

            public function getName(): string
            {
                return $this->name;
            }
        };
    }

    /**
     * Stands in for the planner's ReservationRepository.
     *
     * @param list<object> $selectable
     * @param list<object> $archived
     */
    private static function catalogue(array $selectable, array $archived = [], bool $supported = true): ReservationCatalogue
    {
        $repository = new class($selectable, $archived) {
            /**
             * @param list<object> $selectable
             * @param list<object> $archived
             */
            public function __construct(private readonly array $selectable, private readonly array $archived)
            {
            }

            /** @return list<object> */
            public function findSelectable(): array
            {
                return $this->selectable;
            }

            public function find(int $id): ?object
            {
                foreach ([...$this->selectable, ...$this->archived] as $reservation) {
                    if ($reservation->getId() === $id) {
                        return $reservation;
                    }
                }

                return null;
            }
        };

        return new ReservationCatalogue(new PlannerCapabilities(true, true, $supported), $repository);
    }

    public function testKeysAreTheReservationIds(): void
    {
        $buffer = self::reservation(3, 'Agency Buffer');
        $old = self::reservation(4, 'Old');
        $catalogue = self::catalogue([$buffer, self::reservation(7, 'Tech leading')], [$old]);

        self::assertSame(['3' => 'Agency Buffer', '7' => 'Tech leading'], $catalogue->selectable());
        self::assertSame($buffer, $catalogue->find('3'));
        // archived ones are still known, so a mapping to one keeps its name
        self::assertTrue($catalogue->exists('4'));
        self::assertSame('Old', $catalogue->name('4'));
        self::assertFalse($catalogue->exists('99'));
        self::assertSame('99', $catalogue->name('99'));
        // a key from the provider version is no ID
        self::assertNull($catalogue->find('agency-buffer'));
        self::assertSame('3', $catalogue->keyByName('  agency buffer '));
        self::assertNull($catalogue->keyByName('Old'));
    }

    public function testWithoutReservedTimeNothingIsOffered(): void
    {
        $catalogue = self::catalogue([self::reservation(3, 'Agency Buffer')], [], false);

        self::assertSame([], $catalogue->selectable());
        self::assertNull($catalogue->find('3'));
        self::assertFalse((new ReservationCatalogue(new PlannerCapabilities(true, true, true)))->isSupported());
    }

    public function testAMappingKeepsTheReservationId(): void
    {
        $mapping = new Mapping(Mapping::PROJECT, '42');
        $mapping->apply(Target::reservation('3')->withComment('Daily buffer'));

        self::assertSame('3', $mapping->getReservation());
        $target = $mapping->toTarget();
        self::assertNotNull($target);
        self::assertSame(Target::RESERVATION, $target->kind);
        self::assertSame('3', $target->reservation);
        self::assertSame('Daily buffer', $target->comment);
    }
}
