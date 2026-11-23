# Endurance Activity Analysis

Streaming GPS distance, speed and movement estimates for PHP 8.5.

```bash
composer require endurance/activity-analysis
```

```php
use Endurance\ActivityAnalysis\TrackAnalyzer;

$analyzer = new TrackAnalyzer();
$analyzer->push(50.35294, 65.93432, 0, 1);
$step = $analyzer->push(50.35297, 65.93439, 1_000_000, 1);
$statistics = $analyzer->statistics();
```

`push()` takes degrees, integer microseconds, a segment identifier and optional
reported speed in m/s. Times must be chronological; equal times are accepted.
Memory stays constant as the recording grows.

- Results are estimates with explicit origin, algorithm version and thresholds.
  Defaults use a spherical Earth, a 3 m spatial anchor and a 0.5 m/s movement
  threshold; constructor parameters configure the thresholds.
- Segment changes, equal times, gaps over 30 s and implausible speeds break the
  distance calculation. Excluded positive intervals remain unknown, not stopped.
- A reported total distance can replace the displayed total without replacing
  the derived GPS stream. Reported recording duration remains separate.
- Estimates do not establish device timer events, sessions, laps or exact pauses.

License: [MPL-2.0](LICENSE).
