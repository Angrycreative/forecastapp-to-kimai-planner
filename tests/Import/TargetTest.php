<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Tests\Import;

use App\Entity\Customer;
use App\Entity\Project;
use KimaiPlugin\ForecastImportBundle\Import\MappingSet;
use KimaiPlugin\ForecastImportBundle\Import\Target;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Target::class)]
#[CoversClass(MappingSet::class)]
final class TargetTest extends TestCase
{
    public function testRefusesAProjectOfAnotherCustomer(): void
    {
        $project = new Project();
        $project->setCustomer(new Customer('Other'));

        $this->expectException(\InvalidArgumentException::class);
        Target::work(new Customer('Acme'), $project);
    }

    public function testTentativeTagsMatchWithoutRegardToCaseOrSpaces(): void
    {
        $mappings = new MappingSet(tentativeTags: ['Not signed']);

        self::assertTrue($mappings->isTentative(['Live', ' not SIGNED ']));
        self::assertFalse($mappings->isTentative(['Live']));
        self::assertFalse((new MappingSet())->isTentative(['Not signed']));
    }

    public function testReservedTimeNeedsItsKey(): void
    {
        $target = Target::reservation('agency-buffer', true);

        self::assertSame(Target::RESERVATION, $target->kind);
        self::assertSame('agency-buffer', $target->reservation);
        self::assertTrue($target->automatic);
        self::assertNull($target->customer);

        $this->expectException(\InvalidArgumentException::class);
        Target::reservation('');
    }

    public function testACommentIsTrimmedAndOnlyKeptWhereThereIsPlanning(): void
    {
        $target = Target::reservation('agency-buffer', true)->withComment("  Old retainer work \n");

        self::assertSame('Old retainer work', $target->comment);
        self::assertSame('agency-buffer', $target->reservation);
        self::assertTrue($target->automatic);

        self::assertSame('Leave', Target::timeOff()->withComment('Leave')->comment);
        self::assertNull(Target::work(new Customer('Acme'))->withComment('   ')->comment);
        self::assertNull(Target::skip()->withComment('Ignored')->comment);
    }
}
