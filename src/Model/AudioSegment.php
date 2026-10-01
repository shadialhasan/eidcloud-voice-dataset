<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset\Model;

/**
 * Representation of a detected voice segment.
 */
final class AudioSegment
{
    public function __construct(
        public readonly string $id,
        public readonly string $sourceFile,
        public readonly float $startSec,
        public readonly float $endSec,
        public readonly float $durationSec,
        public readonly string $outputPath,
        public readonly string $text = '',
        public readonly string $normalizedText = '',
        public readonly ?float $snrDb = null,
        public readonly ?float $lufs = null,
        public readonly ?float $peakDbfs = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_file' => $this->sourceFile,
            'start_sec' => round($this->startSec, 3),
            'end_sec' => round($this->endSec, 3),
            'duration_sec' => round($this->durationSec, 3),
            'output_path' => $this->outputPath,
            'text' => $this->text,
            'normalized_text' => $this->normalizedText,
            'snr_db' => $this->snrDb !== null ? round($this->snrDb, 2) : null,
            'lufs' => $this->lufs !== null ? round($this->lufs, 2) : null,
            'peak_dbfs' => $this->peakDbfs !== null ? round($this->peakDbfs, 2) : null,
        ];
    }
}
