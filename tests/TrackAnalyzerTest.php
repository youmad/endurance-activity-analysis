<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityAnalysis\Tests;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityAnalysis\TrackAnalyzer;

final class TrackAnalyzerTest extends TestCase
{
    public function testStravaExampleProducesDistanceAndSpeedWithoutSourceExtensions(): void
    {
        $analysis = new TrackAnalyzer();
        $first = $analysis->push(50.35294, 65.93432, 0, 1);
        $second = $analysis->push(50.35297, 65.93439, 1_000_000, 1);
        self::assertNull($first->speedMetresPerSecond);
        self::assertEqualsWithDelta(5.9829, $second->distanceMetres, 0.001);
        self::assertEqualsWithDelta(21.538, $second->speedMetresPerSecond * 3.6, 0.001);
        $summary = $analysis->statistics();
        self::assertSame('derived', $summary['distance']['origin']);
        self::assertSame('gps-track-v1', $summary['distance']['algorithm']);
        self::assertSame(1_000_000, $summary['movingDurationMicroseconds']);
        self::assertSame(0, $summary['stoppedDurationMicroseconds']);
    }

    public function testSegmentsLongGapsAndEqualTimesNeverCreateConnectingDistancesOrStops(): void
    {
        $analysis = new TrackAnalyzer();
        $analysis->push(0, 0, 0, 1);
        $analysis->push(0, 0.0001, 1_000_000, 1);
        $analysis->push(45, 90, 21_000_000, 2);
        $analysis->push(46, 91, 81_000_000, 2);
        $step = $analysis->push(47, 92, 81_000_000, 2);
        $summary = $analysis->statistics();
        self::assertNull($step->speedMetresPerSecond);
        self::assertEqualsWithDelta(11.1195, $step->distanceMetres, 0.001);
        self::assertSame(80_000_000, $summary['unobservedDurationMicroseconds']);
        self::assertSame(0, $summary['stoppedDurationMicroseconds']);
        self::assertSame(3, $summary['excludedIntervals']);
        self::assertTrue($summary['distancePartial']);
        self::assertNull($summary['averageMovingSpeed']);
    }

    public function testImplausibleGpsJumpsDoNotInflateDistanceOrMaximumSpeed(): void
    {
        $analysis = new TrackAnalyzer();
        $analysis->push(0, 0, 0, 1);
        $analysis->push(30, 30, 1_000_000, 1);
        self::assertNull($analysis->statistics()['maximumSpeed']);
        self::assertNull($analysis->statistics()['distance']);
        self::assertSame(1_000_000, $analysis->statistics()['unobservedDurationMicroseconds']);
    }

    public function testBoundedGpsWobbleIsNotAddedToDistance(): void
    {
        $analysis = new TrackAnalyzer();
        for ($i = 0; $i <= 100; ++$i) {
            $analysis->push(0, ($i % 2) * 0.000005, $i * 1_000_000, 1);
        }
        self::assertSame(0.0, $analysis->statistics()['distance']['value']);
        self::assertGreaterThan(90_000_000, $analysis->statistics()['stoppedDurationMicroseconds']);
    }

    public function testAnchorStillAccumulatesSustainedSlowMovement(): void
    {
        $analysis = new TrackAnalyzer();
        for ($i = 0; $i <= 10; ++$i) {
            $analysis->push(0, $i * 0.000009, $i * 1_000_000, 1);
        }
        self::assertGreaterThan(9.0, $analysis->statistics()['distance']['value']);
        self::assertSame(10_000_000, $analysis->statistics()['movingDurationMicroseconds']);
    }

    public function testReportedDistanceAndSpeedTakePriorityButSourceDurationIsKeptSeparate(): void
    {
        $analysis = new TrackAnalyzer();
        $analysis->push(50.43043, 66.3329, 0, 1, 0.85);
        $analysis->push(50.430345, 66.33299333, 52_000_000, 1, 1.04);
        $summary = $analysis->statistics(17394.78, 2_401_757_000);
        self::assertSame(17394.78, $summary['distance']['value']);
        self::assertSame('reported', $summary['distance']['origin']);
        self::assertNull($summary['distance']['algorithm']);
        self::assertSame(1.04, $summary['maximumSpeed']['value']);
        self::assertSame('reported', $summary['maximumSpeed']['origin']);
        self::assertSame(2_401_757_000, $summary['sourceRecordingDurationMicroseconds']);
        self::assertNull($summary['movingDurationMicroseconds']);
        self::assertFalse($summary['distancePartial']);
    }

    public function testStationaryKnownIntervalHasZeroMovementAndNonzeroStoppedTime(): void
    {
        $analysis = new TrackAnalyzer();
        $analysis->push(0, 0, 0, 1);
        $analysis->push(0, 0, 10_000_000, 1);
        self::assertSame(0, $analysis->statistics()['movingDurationMicroseconds']);
        self::assertSame(10_000_000, $analysis->statistics()['stoppedDurationMicroseconds']);
    }

    public function testDatelineCrossingUsesShortDistanceAndSinglePointRemainsUnknown(): void
    {
        $analysis = new TrackAnalyzer();
        $analysis->push(0, 179.9999, 0, 1);
        $initial = $analysis->statistics();
        self::assertNull($initial['distance']);
        self::assertNull($initial['movingDurationMicroseconds']);
        $analysis->push(0, -179.9999, 1_000_000, 1);
        self::assertEqualsWithDelta(22.239, $analysis->statistics()['distance']['value'], 0.001);
    }

    public function testSourceSpeedOutlierDoesNotDominateAnalysis(): void
    {
        $analysis = new TrackAnalyzer();
        $analysis->push(0, 0, 0, 1, 1000000);
        $analysis->push(0, 0, 1_000_000, 1, 1000000);
        self::assertSame(0.0, $analysis->statistics()['maximumSpeed']['value']);
        self::assertSame('derived', $analysis->statistics()['maximumSpeed']['origin']);
    }

    public function testRejectsBackwardsTime(): void
    {
        $analysis = new TrackAnalyzer();
        $analysis->push(0, 0, 10, 1);
        $this->expectException(\InvalidArgumentException::class);
        $analysis->push(0, 0, 9, 1);
    }
}
