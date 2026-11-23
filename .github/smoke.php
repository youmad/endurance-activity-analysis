<?php

declare(strict_types=1);

/** @param Closure(bool): void $ensure */
return static function (Closure $ensure, string $autoload, string $package): void {
    $analysis = new Endurance\ActivityAnalysis\TrackAnalyzer();
    $analysis->push(0, 0, 0, 1);
    $analysis->push(0, 0.0001, 10_000_000, 1);
    $summary = $analysis->statistics();
    $ensure($summary['distance']['value'] > 10 && 'derived' === $summary['distance']['origin']);
};
