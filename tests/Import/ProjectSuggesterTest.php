<?php

declare(strict_types=1);

/*
 * This file is part of the ForecastImportBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ForecastImportBundle\Tests\Import;

use KimaiPlugin\ForecastImportBundle\Import\ProjectCandidate;
use KimaiPlugin\ForecastImportBundle\Import\ProjectSuggester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectSuggester::class)]
final class ProjectSuggesterTest extends TestCase
{
    private ProjectSuggester $suggester;

    protected function setUp(): void
    {
        // shaped like a real agency's Kimai: many "Marketing Services" and "Service & Support" projects
        $this->suggester = new ProjectSuggester([
            new ProjectCandidate(1, 'Psykologpartners W & W AB', 11, 'Psykologpartners Marketing Services'),
            new ProjectCandidate(1, 'Psykologpartners W & W AB', 12, 'Service & Support (psykologpartners.se)'),
            new ProjectCandidate(2, 'Ankarsrum Kitchen AB', 21, 'Ankarsrum Marketing Services'),
            new ProjectCandidate(3, 'Cyntora AB', 31, 'Marketing'),
            new ProjectCandidate(4, 'Angry Creative SE', 41, 'Mercuri Support'),
            new ProjectCandidate(4, 'Angry Creative SE', 42, 'CRM'),
            new ProjectCandidate(5, 'Hydnet AB', 51, 'Hydnet Marketing Services'),
            new ProjectCandidate(6, 'KFAB', 61, 'KFAB Marketing Services'),
            new ProjectCandidate(7, 'Mercuri International Group AB', 71, 'Service & Support (mercuri.net)'),
        ]);
    }

    public function testMatchesDriftedNamesOfTheSameCustomer(): void
    {
        $best = $this->suggester->suggest('Psykologpartners', 'Psykologpartners Marketing')[0];

        self::assertSame(11, $best['candidate']->projectId);
        // "Psykologpartners" only partly matches "Psykologpartners W & W AB": a good suggestion, left for a person to confirm
        self::assertGreaterThanOrEqual(ProjectSuggester::MINIMUM_SCORE, $best['score']);
        self::assertLessThan(ProjectSuggester::CONFIDENT_SCORE, $best['score']);
    }

    public function testIsConfidentWhenOnlyAGenericWordDiffers(): void
    {
        $best = $this->suggester->suggest('Hydnet', 'Hydnet Marketing')[0];

        self::assertSame(51, $best['candidate']->projectId);
        self::assertGreaterThanOrEqual(ProjectSuggester::CONFIDENT_SCORE, $best['score']);
    }

    public function testFindsTheEndCustomerNamedInAnAgencyProject(): void
    {
        self::assertSame(21, $this->suggester->suggest('Cyntora', 'Ankarsrum Marketing')[0]['candidate']->projectId);
    }

    public function testACommonWordAloneIsNotAMatch(): void
    {
        self::assertSame([], $this->suggester->suggest('Angry Creative SE', 'Agency Buffer'));
    }

    public function testWorksWithoutAClient(): void
    {
        self::assertSame(51, $this->suggester->suggest(null, 'Hydnet Marketing')[0]['candidate']->projectId);
    }

    public function testLimitsAndOrdersBestFirst(): void
    {
        $suggestions = $this->suggester->suggest('KFAB', 'KFAB Marketing', 2);

        self::assertLessThanOrEqual(2, \count($suggestions));
        self::assertSame(61, $suggestions[0]['candidate']->projectId);
        if (isset($suggestions[1])) {
            self::assertGreaterThanOrEqual($suggestions[1]['score'], $suggestions[0]['score']);
        }
    }

    public function testNormaliseDropsPunctuationCaseAndCompanyForms(): void
    {
        self::assertSame(['psykologpartners w', ['psykologpartners', 'w']], ProjectSuggester::normalise('Psykologpartners W & W AB'));
        self::assertSame(['växjö el', ['växjö', 'el']], ProjectSuggester::normalise('VÄXJÖ  El, Ltd.'));
    }
}
