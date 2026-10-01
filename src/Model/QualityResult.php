<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset\Model;

/**
 * Result of an audio quality gate evaluation.
 */
final class QualityResult
{
    /**
     * @param bool $passed
     * @param float $snrDb
     * @param float $peakDbfs
     * @param bool $isClipped
     * @param bool $hasHum
     * @param array<string> $reasons
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public readonly bool $passed,
        public readonly float $snrDb,
        public readonly float $peakDbfs,
        public readonly bool $isClipped,
        public readonly bool $hasHum,
        public readonly array $reasons = [],
        public readonly array $metrics = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'snr_db' => round($this->snrDb, 2),
            'peak_dbfs' => round($this->peakDbfs, 2),
            'is_clipped' => $this->isClipped,
            'has_hum' => $this->hasHum,
            'reasons' => $this->reasons,
            'metrics' => $this->metrics,
        ];
    }
}
