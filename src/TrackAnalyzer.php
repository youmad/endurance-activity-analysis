<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityAnalysis;

final class TrackAnalyzer
{
    public const string VERSION = 'gps-track-v1';
    private ?int $previousTime = null;
    private int $segment = 0;
    private float $previousLatitude = 0;
    private float $previousLongitude = 0;
    private float $anchorLatitude = 0;
    private float $anchorLongitude = 0;
    private int $anchorTime = 0;
    private float $distance = 0;
    private int $moving = 0;
    private int $stopped = 0;
    private int $unobserved = 0;
    private int $links = 0;
    private int $excluded = 0;
    private ?float $maximum = null;
    private string $maximumOrigin = 'derived';

    public function __construct(
        private readonly float $movingThreshold = 0.5,
        private readonly float $maximumSpeed = 100.0,
        private readonly int $maximumGapMicroseconds = 30_000_000,
        private readonly float $jitterRadiusMetres = 3.0,
    ) {
        if (!is_finite($movingThreshold) || !is_finite($maximumSpeed) || !is_finite($jitterRadiusMetres)
            || $movingThreshold < 0 || $maximumSpeed <= $movingThreshold || $maximumGapMicroseconds <= 0 || $jitterRadiusMetres < 0) {
            throw new \InvalidArgumentException('Invalid track analysis parameters.');
        }
    }

    public function push(float $latitude, float $longitude, int $time, int $segment, ?float $reportedSpeed = null): TrackStep
    {
        if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            throw new \InvalidArgumentException('Invalid track coordinate.');
        }
        if (null !== $this->previousTime && $time < $this->previousTime) {
            throw new \InvalidArgumentException('Track times must be chronological.');
        }

        if (null !== $reportedSpeed && (!is_finite($reportedSpeed) || $reportedSpeed < 0 || $reportedSpeed > $this->maximumSpeed)) {
            $reportedSpeed = null;
        }
        $speed = null;
        $reset = null === $this->previousTime;
        if (null !== $this->previousTime) {
            $dt = $time - $this->previousTime;
            $leg = self::metres($this->previousLatitude, $this->previousLongitude, $latitude, $longitude);
            $reset = $segment !== $this->segment || $dt <= 0 || $dt > $this->maximumGapMicroseconds || $leg > $this->maximumSpeed * ($dt / 1_000_000);
            if ($reset) {
                $this->unobserved += $dt;
                ++$this->excluded;
            } else {
                ++$this->links;
                // A fixed anchor filters GPS wobble without losing sustained slow movement.
                $displacement = self::metres($this->anchorLatitude, $this->anchorLongitude, $latitude, $longitude);
                $speed = $displacement / (($time - $this->anchorTime) / 1_000_000);
                if ($displacement >= $this->jitterRadiusMetres) {
                    $this->distance += $displacement;
                    $reset = true;
                }
                if (($reportedSpeed ?? $speed) >= $this->movingThreshold) {
                    $this->moving += $dt;
                } else {
                    $this->stopped += $dt;
                }
            }
        }
        if ($reset) {
            $this->anchorLatitude = $latitude;
            $this->anchorLongitude = $longitude;
            $this->anchorTime = $time;
        }
        $this->previousLatitude = $latitude;
        $this->previousLongitude = $longitude;
        $this->previousTime = $time;
        $this->segment = $segment;
        $selected = $reportedSpeed ?? $speed;
        $origin = null !== $reportedSpeed ? 'reported' : 'derived';
        if (null !== $selected && (null === $this->maximum || $selected > $this->maximum)) {
            $this->maximum = $selected;
            $this->maximumOrigin = $origin;
        }

        return new TrackStep($this->distance, $speed, null !== $speed);
    }

    /** @return array<string, mixed> */
    public function statistics(?float $reportedDistanceMetres = null, ?int $sourceRecordingDurationMicroseconds = null): array
    {
        if (null !== $reportedDistanceMetres && (!is_finite($reportedDistanceMetres) || $reportedDistanceMetres < 0)) {
            throw new \InvalidArgumentException('Invalid reported distance.');
        }
        if (null !== $sourceRecordingDurationMicroseconds && $sourceRecordingDurationMicroseconds < 0) {
            throw new \InvalidArgumentException('Invalid source recording duration.');
        }
        $gpsDistance = $this->links > 0 ? $this->distance : null;
        $distance = $reportedDistanceMetres ?? $gpsDistance;

        // Gaps are unknown intervals, never automatically labelled as stops.
        $average = null !== $distance && $this->moving > 0 && 0 === $this->excluded ? $distance / ($this->moving / 1_000_000) : null;

        if (null !== $average && $average > $this->maximumSpeed) {
            $average = null;
        }

        return [
            'algorithm' => self::VERSION,
            'parameters' => ['movingThresholdMetresPerSecond' => $this->movingThreshold, 'maximumSpeedMetresPerSecond' => $this->maximumSpeed, 'maximumGapMicroseconds' => $this->maximumGapMicroseconds, 'jitterRadiusMetres' => $this->jitterRadiusMetres],
            'distance' => null === $distance ? null : self::metric($distance, 'm', null !== $reportedDistanceMetres ? 'reported' : 'derived'),
            'gpsDistance' => null === $gpsDistance ? null : self::metric($gpsDistance, 'm'),
            'distancePartial' => null === $reportedDistanceMetres && $this->excluded > 0,
            'movingDurationMicroseconds' => $this->links > 0 ? $this->moving : null,
            'stoppedDurationMicroseconds' => $this->links > 0 ? $this->stopped : null,
            'unobservedDurationMicroseconds' => $this->unobserved,
            'excludedIntervals' => $this->excluded,
            'averageMovingSpeed' => null === $average ? null : self::metric($average, 'm/s'),
            'maximumSpeed' => null === $this->maximum ? null : self::metric($this->maximum, 'm/s', $this->maximumOrigin),
            'sourceRecordingDurationMicroseconds' => $sourceRecordingDurationMicroseconds,
        ];
    }

    /** @return array{value: float, unit: string, origin: string, algorithm: ?string} */
    private static function metric(float $value, string $unit, string $origin = 'derived'): array
    {
        return ['value' => $value, 'unit' => $unit, 'origin' => $origin, 'algorithm' => 'derived' === $origin ? self::VERSION : null];
    }

    private static function metres(float $a, float $b, float $c, float $d): float
    {
        $h = sin(deg2rad($c - $a) / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin(deg2rad($d - $b) / 2) ** 2;

        return 12_742_017.6 * asin(sqrt(max(0.0, min(1.0, $h))));
    }
}
