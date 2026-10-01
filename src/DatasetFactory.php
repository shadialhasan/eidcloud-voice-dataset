<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset;

use EidCloud\VoiceDataset\Audio\SilenceTrimmer;
use EidCloud\VoiceDataset\Audio\LoudnessNormalizer;
use EidCloud\VoiceDataset\Audio\SmartSegmenter;
use EidCloud\VoiceDataset\Audio\WavProcessor;
use EidCloud\VoiceDataset\Quality\QualityGate;
use EidCloud\VoiceDataset\Exporter\LjSpeechExporter;
use EidCloud\VoiceDataset\Model\AudioSegment;
use EidCloud\VoiceDataset\Model\QualityResult;
use RuntimeException;

/**
 * Automated Voice Dataset Factory for Speech AI, TTS (XTTS/Bark), and ASR training data.
 */
class DatasetFactory
{
    private SilenceTrimmer $trimmer;
    private LoudnessNormalizer $normalizer;
    private SmartSegmenter $segmenter;
    private QualityGate $qualityGate;
    private LjSpeechExporter $exporter;

    /**
     * @param array{
     *     target_lufs?: float,
     *     silence_threshold_db?: float,
     *     min_segment_sec?: float,
     *     max_segment_sec?: float,
     *     min_snr_db?: float,
     *     train_ratio?: float,
     *     target_sample_rate?: int
     * } $config
     */
    public function __construct(
        private readonly array $config = []
    ) {
        $targetLufs = $this->config['target_lufs'] ?? -23.0;
        $silenceDb = $this->config['silence_threshold_db'] ?? -45.0;
        $minSec = $this->config['min_segment_sec'] ?? 2.0;
        $maxSec = $this->config['max_segment_sec'] ?? 12.0;
        $minSnr = $this->config['min_snr_db'] ?? 15.0;
        $trainRatio = $this->config['train_ratio'] ?? 0.90;

        $this->trimmer = new SilenceTrimmer(silenceThresholdDb: $silenceDb);
        $this->normalizer = new LoudnessNormalizer(targetLufs: $targetLufs);
        $this->segmenter = new SmartSegmenter(
            minSegmentSec: $minSec,
            maxSegmentSec: $maxSec,
            silenceThresholdDb: $silenceDb
        );
        $this->qualityGate = new QualityGate(minSnrDb: $minSnr);
        $this->exporter = new LjSpeechExporter(trainRatio: $trainRatio);
    }

    /**
     * Process an input raw audio directory into a standardized dataset directory.
     *
     * @param string $rawAudioDir Directory containing source audio files (.wav, .mp3, etc.)
     * @param string $outputDir Target directory for LJSpeech dataset
     * @param array<string, string> $transcripts Optional map of filename => transcript text
     * @param callable|null $progressCallback Optional callback fn(string $event, array $data): void
     * @return array<string, mixed> Execution summary
     */
    public function build(
        string $rawAudioDir,
        string $outputDir,
        array $transcripts = [],
        ?callable $progressCallback = null
    ): array {
        if (!is_dir($rawAudioDir)) {
            throw new RuntimeException("Raw audio directory does not exist: {$rawAudioDir}");
        }

        $wavsDir = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wavs';
        if (!is_dir($wavsDir)) {
            mkdir($wavsDir, 0777, true);
        }

        $audioFiles = $this->scanAudioFiles($rawAudioDir);
        if (empty($audioFiles)) {
            throw new RuntimeException("No supported audio files found in: {$rawAudioDir}");
        }

        $tempDir = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_tmp_processing';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $acceptedSegments = [];
        $rejectedSegments = [];
        $globalIndex = 1;

        foreach ($audioFiles as $sourceFile) {
            $baseName = pathinfo($sourceFile, PATHINFO_FILENAME);
            if ($progressCallback) {
                $progressCallback('file_start', ['file' => $sourceFile]);
            }

            // Step 1: Pre-convert to 22050Hz/16-bit PCM WAV if needed
            $convertedPath = $tempDir . DIRECTORY_SEPARATOR . $baseName . '_standard.wav';
            $this->standardizeAudio($sourceFile, $convertedPath);

            // Step 2: Quality Gate on raw source
            $rawQuality = $this->qualityGate->evaluate($convertedPath);
            if (!$rawQuality->passed) {
                $rejectedSegments[] = [
                    'source' => $sourceFile,
                    'reason' => 'Failed raw quality gate: ' . implode('; ', $rawQuality->reasons),
                    'metrics' => $rawQuality->toArray(),
                ];
                if ($progressCallback) {
                    $progressCallback('file_rejected', ['file' => $sourceFile, 'reason' => $rawQuality->reasons]);
                }
                continue;
            }

            // Step 3: VAD & Silence Trimming
            $trimmedPath = $tempDir . DIRECTORY_SEPARATOR . $baseName . '_trimmed.wav';
            $this->trimmer->trim($convertedPath, $trimmedPath);

            // Step 4: Smart Acoustic Segmentation
            $segPrefix = preg_replace('/[^A-Za-z0-9]/', '', $baseName);
            if (empty($segPrefix)) {
                $segPrefix = 'SPK';
            }
            $rawSegments = $this->segmenter->segmentAndSave($trimmedPath, $tempDir, $segPrefix);

            // Step 5: Process each slice (Loudness Normalize & Quality Validate)
            foreach ($rawSegments as $seg) {
                $slicePath = $seg['path'];
                $segId = sprintf('VOICE_%06d', $globalIndex++);
                $finalWavPath = $wavsDir . DIRECTORY_SEPARATOR . $segId . '.wav';

                // Loudness normalization to target LUFS
                $normStats = $this->normalizer->normalize($slicePath, $finalWavPath);

                // Segment quality gate
                $sliceQuality = $this->qualityGate->evaluate($finalWavPath);
                if (!$sliceQuality->passed) {
                    if (file_exists($finalWavPath)) {
                        unlink($finalWavPath);
                    }
                    $rejectedSegments[] = [
                        'source' => $sourceFile,
                        'segment_id' => $segId,
                        'reason' => 'Segment quality failed: ' . implode('; ', $sliceQuality->reasons),
                        'metrics' => $sliceQuality->toArray(),
                    ];
                    continue;
                }

                // Check text transcript if supplied
                $rawText = $transcripts[$baseName] ?? $transcripts[basename($sourceFile)] ?? '';
                $cleanText = $rawText !== '' ? $rawText : "Transcription placeholder for segment {$segId}.";
                $normalizedText = $cleanText;

                $header = WavProcessor::readHeader($finalWavPath);

                $audioSeg = new AudioSegment(
                    id: $segId,
                    sourceFile: basename($sourceFile),
                    startSec: $seg['start'],
                    endSec: $seg['end'],
                    durationSec: $header['durationSec'],
                    outputPath: $finalWavPath,
                    text: $cleanText,
                    normalizedText: $normalizedText,
                    snrDb: $sliceQuality->snrDb,
                    lufs: $normStats['integrated_loudness'],
                    peakDbfs: $sliceQuality->peakDbfs
                );

                $acceptedSegments[] = $audioSeg;
            }

            if ($progressCallback) {
                $progressCallback('file_complete', ['file' => $sourceFile]);
            }
        }

        // Cleanup temp processing directory
        $this->deleteDirectory($tempDir);

        if (empty($acceptedSegments)) {
            throw new RuntimeException("No segments passed the audio quality and segmentation gates.");
        }

        // Step 6: Export metadata.csv, train.csv, val.csv, and stats.json
        $exportResult = $this->exporter->export($acceptedSegments, $outputDir, [
            'raw_files_analyzed' => count($audioFiles),
            'accepted_segments' => count($acceptedSegments),
            'rejected_segments' => count($rejectedSegments),
            'rejection_details' => $rejectedSegments,
            'config' => [
                'target_lufs' => $this->config['target_lufs'] ?? -23.0,
                'min_segment_sec' => $this->config['min_segment_sec'] ?? 2.0,
                'max_segment_sec' => $this->config['max_segment_sec'] ?? 12.0,
                'min_snr_db' => $this->config['min_snr_db'] ?? 15.0,
            ]
        ]);

        return [
            'status' => 'success',
            'output_directory' => $outputDir,
            'summary' => $exportResult,
            'accepted' => count($acceptedSegments),
            'rejected' => count($rejectedSegments),
        ];
    }

    /**
     * Audit an existing dataset directory for audio defects, clipping, and format compliance.
     *
     * @param string $datasetDir
     * @param bool $strict
     * @return array<string, mixed>
     */
    public function audit(string $datasetDir, bool $strict = false): array
    {
        if (!is_dir($datasetDir)) {
            throw new RuntimeException("Dataset directory does not exist: {$datasetDir}");
        }

        $wavsDir = rtrim($datasetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wavs';
        $metadataFile = rtrim($datasetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'metadata.csv';

        $issues = [];
        $stats = [
            'total_files' => 0,
            'compliant_files' => 0,
            'failed_files' => 0,
            'total_duration_sec' => 0.0,
            'average_snr_db' => 0.0,
            'clipping_incidents' => 0,
            'hum_incidents' => 0,
            'metadata_mismatches' => 0,
        ];

        if (!is_dir($wavsDir)) {
            $issues[] = "Missing 'wavs/' directory.";
        }
        if (!file_exists($metadataFile)) {
            $issues[] = "Missing 'metadata.csv' file.";
        }

        // Check metadata entries
        $metaIds = [];
        if (file_exists($metadataFile)) {
            $lines = file($metadataFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $lineNum => $line) {
                $cols = explode('|', $line);
                if (count($cols) < 2) {
                    $issues[] = "Invalid line " . ($lineNum + 1) . " in metadata.csv: delimiter '|' not found";
                    continue;
                }
                $id = trim($cols[0]);
                $metaIds[$id] = true;
            }
        }

        $wavFiles = glob($wavsDir . DIRECTORY_SEPARATOR . '*.wav') ?: [];
        $stats['total_files'] = count($wavFiles);

        $snrSum = 0.0;
        $evalCount = 0;

        foreach ($wavFiles as $wav) {
            $id = pathinfo($wav, PATHINFO_FILENAME);
            if (!isset($metaIds[$id])) {
                $stats['metadata_mismatches']++;
                if ($strict) {
                    $issues[] = "WAV file {$id}.wav exists but is missing from metadata.csv";
                }
            }

            try {
                $res = $this->qualityGate->evaluate($wav);
                $header = WavProcessor::readHeader($wav);
                $duration = $header['durationSec'];
                $stats['total_duration_sec'] += $duration;
                $snrSum += $res->snrDb;
                $evalCount++;

                if ($res->isClipped) {
                    $stats['clipping_incidents']++;
                }
                if ($res->hasHum) {
                    $stats['hum_incidents']++;
                }

                if (!$res->passed) {
                    $stats['failed_files']++;
                    if ($strict) {
                        $issues[] = "Quality failure on {$id}.wav: " . implode('; ', $res->reasons);
                    }
                } else {
                    $stats['compliant_files']++;
                }
            } catch (\Throwable $e) {
                $stats['failed_files']++;
                $issues[] = "Error processing {$id}.wav: " . $e->getMessage();
            }
        }

        if ($evalCount > 0) {
            $stats['average_snr_db'] = round($snrSum / $evalCount, 2);
        }
        $stats['total_duration_sec'] = round($stats['total_duration_sec'], 3);

        $passed = empty($issues) && ($strict ? $stats['failed_files'] === 0 : true);

        return [
            'dataset_dir' => $datasetDir,
            'passed' => $passed,
            'strict_mode' => $strict,
            'stats' => $stats,
            'issues' => $issues,
            'checked_at' => date('c'),
        ];
    }

    /**
     * Ensure audio is 22050 Hz 16-bit mono PCM WAV.
     */
    private function standardizeAudio(string $inputPath, string $outputPath): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $targetRate = $this->config['target_sample_rate'] ?? 22050;

        if ($this->hasFFmpeg()) {
            $cmd = sprintf(
                'ffmpeg -y -v error -i %s -ac 1 -ar %d -c:a pcm_s16le %s 2>&1',
                escapeshellarg($inputPath),
                $targetRate,
                escapeshellarg($outputPath)
            );
            exec($cmd, $o, $rc);
            if ($rc === 0 && file_exists($outputPath) && filesize($outputPath) > 44) {
                return;
            }
        }

        // Pure PHP copy / write
        $samples = WavProcessor::readNormalizedSamples($inputPath);
        $header = WavProcessor::readHeader($inputPath);
        WavProcessor::writeWav($outputPath, $samples, $header['sampleRate']);
    }

    private function scanAudioFiles(string $dir): array
    {
        $extensions = ['wav', 'mp3', 'flac', 'ogg', 'm4a', 'aac'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $ext = strtolower($item->getExtension());
                if (in_array($ext, $extensions, true)) {
                    $files[] = $item->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function hasFFmpeg(): bool
    {
        $cmd = DIRECTORY_SEPARATOR === '\\' ? 'where ffmpeg 2>nul' : 'which ffmpeg 2>/dev/null';
        $out = shell_exec($cmd);
        return !empty(trim((string)$out));
    }
}
