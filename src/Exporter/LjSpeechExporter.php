<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset\Exporter;

use EidCloud\VoiceDataset\Model\AudioSegment;
use RuntimeException;

/**
 * Exporter for the standard LJSpeech dataset format.
 * Generates:
 *  - wavs/ (standardized audio files)
 *  - metadata.csv (ID|RawText|NormalizedText)
 *  - train.csv
 *  - val.csv
 *  - stats.json (sampling stats, durations, SNR distributions)
 */
class LjSpeechExporter
{
    /**
     * @param float $trainRatio Fraction of data for training split (e.g. 0.90)
     * @param string $delimiter LJSpeech uses '|' delimiter
     */
    public function __construct(
        private readonly float $trainRatio = 0.90,
        private readonly string $delimiter = '|'
    ) {
    }

    /**
     * Export segments to output dataset directory.
     *
     * @param array<AudioSegment> $segments
     * @param string $outputDir
     * @param array<string, mixed> $extraStats
     * @return array{
     *     total_segments: int,
     *     train_count: int,
     *     val_count: int,
     *     total_duration_sec: float,
     *     avg_duration_sec: float,
     *     metadata_path: string,
     *     train_path: string,
     *     val_path: string,
     *     stats_path: string
     * }
     */
    public function export(array $segments, string $outputDir, array $extraStats = []): array
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $wavsDir = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wavs';
        if (!is_dir($wavsDir)) {
            mkdir($wavsDir, 0777, true);
        }

        $metadataPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'metadata.csv';
        $trainPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'train.csv';
        $valPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'val.csv';
        $statsPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'stats.json';

        $totalDuration = 0.0;
        $rows = [];

        foreach ($segments as $seg) {
            $totalDuration += $seg->durationSec;
            $raw = $seg->text !== '' ? $seg->text : $seg->id;
            $norm = $seg->normalizedText !== '' ? $seg->normalizedText : $raw;

            // Clean linebreaks or delimiter in text
            $rawClean = str_replace(["\r", "\n", $this->delimiter], [' ', ' ', ' '], trim($raw));
            $normClean = str_replace(["\r", "\n", $this->delimiter], [' ', ' ', ' '], trim($norm));

            $rows[] = [
                'id' => $seg->id,
                'raw' => $rawClean,
                'norm' => $normClean,
                'segment' => $seg,
            ];
        }

        // Deterministic split (shuffled with fixed seed)
        mt_srand(42);
        $indices = array_keys($rows);
        shuffle($indices);

        $totalCount = count($rows);
        $trainCount = (int)round($totalCount * $this->trainRatio);
        if ($totalCount > 1 && $trainCount === $totalCount) {
            $trainCount = $totalCount - 1; // Ensure at least 1 val sample if > 1 sample
        }
        $valCount = $totalCount - $trainCount;

        $trainSet = array_flip(array_slice($indices, 0, $trainCount));

        $fpMeta = fopen($metadataPath, 'wb');
        $fpTrain = fopen($trainPath, 'wb');
        $fpVal = fopen($valPath, 'wb');

        if (!$fpMeta || !$fpTrain || !$fpVal) {
            throw new RuntimeException("Could not open CSV files for writing in: {$outputDir}");
        }

        try {
            foreach ($rows as $idx => $item) {
                $line = sprintf("%s%s%s%s%s\n", $item['id'], $this->delimiter, $item['raw'], $this->delimiter, $item['norm']);
                fwrite($fpMeta, $line);

                if (isset($trainSet[$idx])) {
                    fwrite($fpTrain, $line);
                } else {
                    fwrite($fpVal, $line);
                }
            }
        } finally {
            fclose($fpMeta);
            fclose($fpTrain);
            fclose($fpVal);
        }

        $avgDuration = $totalCount > 0 ? $totalDuration / $totalCount : 0.0;

        $stats = array_merge([
            'dataset_name' => basename($outputDir),
            'format' => 'LJSpeech-1.1',
            'total_samples' => $totalCount,
            'train_samples' => $trainCount,
            'val_samples' => $valCount,
            'train_ratio' => $this->trainRatio,
            'total_duration_sec' => round($totalDuration, 3),
            'total_duration_hours' => round($totalDuration / 3600.0, 4),
            'avg_duration_sec' => round($avgDuration, 3),
            'exported_at' => date('c'),
            'files' => [
                'metadata' => 'metadata.csv',
                'train' => 'train.csv',
                'val' => 'val.csv',
                'wavs' => 'wavs/',
            ]
        ], $extraStats);

        file_put_contents($statsPath, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'total_segments' => $totalCount,
            'train_count' => $trainCount,
            'val_count' => $valCount,
            'total_duration_sec' => round($totalDuration, 3),
            'avg_duration_sec' => round($avgDuration, 3),
            'metadata_path' => $metadataPath,
            'train_path' => $trainPath,
            'val_path' => $valPath,
            'stats_path' => $statsPath,
        ];
    }
}
