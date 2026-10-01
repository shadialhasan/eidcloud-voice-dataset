<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset\Tests;

use EidCloud\VoiceDataset\Audio\LoudnessNormalizer;
use EidCloud\VoiceDataset\Audio\SilenceTrimmer;
use EidCloud\VoiceDataset\Audio\SmartSegmenter;
use EidCloud\VoiceDataset\Audio\WavProcessor;
use EidCloud\VoiceDataset\Quality\QualityGate;
use EidCloud\VoiceDataset\Exporter\LjSpeechExporter;
use EidCloud\VoiceDataset\DatasetFactory;
use EidCloud\VoiceDataset\Model\AudioSegment;

/**
 * Automated test suite for EidCloud Voice Dataset Factory.
 */
class VoiceDatasetTest
{
    private string $testTmpDir;

    public function __construct()
    {
        $this->testTmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eidcloud_voice_tests_' . uniqid();
        mkdir($this->testTmpDir, 0777, true);
    }

    public function __destruct()
    {
        $this->cleanDir($this->testTmpDir);
    }

    /**
     * Generate synthetic audio WAV with tone, speech-like harmonics, and silences.
     *
     * @param float $durationSec
     * @param float $toneFreq
     * @param float $amplitude
     * @param float $silenceLeadingSec
     * @param float $silenceTrailingSec
     * @param float $noiseFloor
     */
    public function generateSyntheticWav(
        string $path,
        float $durationSec = 6.0,
        float $toneFreq = 440.0,
        float $amplitude = 0.5,
        float $silenceLeadingSec = 0.5,
        float $silenceTrailingSec = 0.5,
        float $noiseFloor = 0.002
    ): void {
        $sampleRate = 22050;
        $totalSamples = (int)($durationSec * $sampleRate);
        $samples = [];

        $leadingSamples = (int)($silenceLeadingSec * $sampleRate);
        $trailingSamples = (int)($silenceTrailingSec * $sampleRate);
        $activeEnd = $totalSamples - $trailingSamples;

        for ($i = 0; $i < $totalSamples; $i++) {
            $noise = ((mt_rand() / mt_getrandmax()) * 2.0 - 1.0) * $noiseFloor;

            if ($i < $leadingSamples || $i >= $activeEnd) {
                // Silence section with slight ambient floor
                $samples[] = $noise;
            } else {
                // Speech-like harmonic tone
                $t = (float)$i / $sampleRate;
                $signal = sin(2 * M_PI * $toneFreq * $t) * 0.7
                    + sin(2 * M_PI * ($toneFreq * 2) * $t) * 0.2
                    + sin(2 * M_PI * ($toneFreq * 3) * $t) * 0.1;
                $samples[] = max(-1.0, min(1.0, ($signal * $amplitude) + $noise));
            }
        }

        WavProcessor::writeWav($path, $samples, $sampleRate);
    }

    public function testWavProcessorReadWrite(): void
    {
        $wavPath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'test_rw.wav';
        $this->generateSyntheticWav($wavPath, durationSec: 2.0, amplitude: 0.6);

        $header = WavProcessor::readHeader($wavPath);
        $this->assertEquals(22050, $header['sampleRate'], 'WAV sample rate mismatch');
        $this->assertEquals(1, $header['channels'], 'WAV channels mismatch');
        $this->assertEquals(16, $header['bitsPerSample'], 'WAV bits per sample mismatch');
        $this->assertNear(2.0, $header['durationSec'], 0.05, 'WAV duration mismatch');

        $samples = WavProcessor::readNormalizedSamples($wavPath);
        $this->assertEquals(count($samples), $header['totalSamples'], 'Sample count mismatch');

        $slicePath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'test_slice.wav';
        WavProcessor::sliceWav($wavPath, $slicePath, 0.5, 1.5);
        $sliceHeader = WavProcessor::readHeader($slicePath);
        $this->assertNear(1.0, $sliceHeader['durationSec'], 0.05, 'Sliced WAV duration mismatch');
    }

    public function testSilenceTrimming(): void
    {
        $wavPath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'test_trim.wav';
        $outPath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'test_trim_out.wav';

        // 1 sec silence + 2 sec speech + 1 sec silence = 4 sec
        $this->generateSyntheticWav(
            $wavPath,
            durationSec: 4.0,
            amplitude: 0.7,
            silenceLeadingSec: 1.0,
            silenceTrailingSec: 1.0
        );

        $trimmer = new SilenceTrimmer(silenceThresholdDb: -35.0, minSilenceDurationSec: 0.2);
        $res = $trimmer->trimWithPurePhp($wavPath, $outPath);

        $this->assertTrue(file_exists($outPath), 'Trimmed output file must exist');
        $this->assertNear(4.0, $res['original_duration'], 0.1, 'Original duration mismatch');
        $this->assertTrue($res['trimmed_duration'] < 3.2, 'Trimmed duration must remove silences');
        $this->assertTrue($res['trimmed_duration'] >= 1.8, 'Speech content must be preserved');
    }

    public function testLoudnessNormalization(): void
    {
        $wavPath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'test_norm.wav';
        $outPath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'test_norm_out.wav';

        // Low volume audio
        $this->generateSyntheticWav($wavPath, durationSec: 3.0, amplitude: 0.05, silenceLeadingSec: 0.1, silenceTrailingSec: 0.1);

        $normalizer = new LoudnessNormalizer(targetLufs: -23.0);
        $res = $normalizer->normalizeWithPurePhp($wavPath, $outPath);

        $this->assertTrue(file_exists($outPath), 'Normalized output file must exist');
        $this->assertEquals(-23.0, $res['target_lufs'], 'Target LUFS must be -23.0');

        $outSamples = WavProcessor::readNormalizedSamples($outPath);
        $peak = 0.0;
        foreach ($outSamples as $s) {
            $peak = max($peak, abs($s));
        }

        // Low volume (0.05) should be amplified without clipping
        $this->assertTrue($peak > 0.1, 'Normalized peak should be amplified higher than 0.1');
        $this->assertTrue($peak <= 1.0, 'Peak must never exceed 1.0 (clipping prevention)');
    }

    public function testSmartSegmentation(): void
    {
        $wavPath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'test_segment.wav';
        $sampleRate = 22050;
        $samples = [];

        // Build continuous recording: 3s sound, 0.6s pause, 4s sound, 0.6s pause, 3s sound = ~11.2s
        $block = function (float $dur, bool $sound) use (&$samples, $sampleRate) {
            $n = (int)($dur * $sampleRate);
            for ($i = 0; $i < $n; $i++) {
                if ($sound) {
                    $t = (float)$i / $sampleRate;
                    $samples[] = sin(2 * M_PI * 300 * $t) * 0.5;
                } else {
                    $samples[] = 0.001 * ((mt_rand() / mt_getrandmax()) * 2 - 1);
                }
            }
        };

        $block(3.0, true);
        $block(0.6, false);
        $block(4.0, true);
        $block(0.6, false);
        $block(3.0, true);

        WavProcessor::writeWav($wavPath, $samples, $sampleRate);

        $segmenter = new SmartSegmenter(
            minSegmentSec: 2.0,
            maxSegmentSec: 10.0,
            silenceThresholdDb: -30.0,
            pauseDurationSec: 0.3
        );

        $segments = $segmenter->detectSegments($wavPath);
        $this->assertTrue(count($segments) >= 2, 'Must detect at least 2 segments around pauses');

        foreach ($segments as $seg) {
            $this->assertTrue($seg['duration'] >= 1.9, 'Segment must be >= minSegmentSec');
            $this->assertTrue($seg['duration'] <= 10.5, 'Segment must be <= maxSegmentSec');
        }

        $sliceDir = $this->testTmpDir . DIRECTORY_SEPARATOR . 'segments';
        $saved = $segmenter->segmentAndSave($wavPath, $sliceDir, 'SEG');
        $this->assertEquals(count($segments), count($saved), 'Saved count must match detected count');
        $this->assertTrue(file_exists($saved[0]['path']), 'Segment WAV file must be written to disk');
    }

    public function testQualityGateClippedAndHum(): void
    {
        $gate = new QualityGate(minSnrDb: 10.0);

        // Good file
        $goodPath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'good.wav';
        $this->generateSyntheticWav($goodPath, durationSec: 3.0, amplitude: 0.6, noiseFloor: 0.001);
        $resGood = $gate->evaluate($goodPath);
        $this->assertTrue($resGood->passed, 'Clean audio should pass quality gate');
        $this->assertFalse($resGood->isClipped, 'Clean audio must not be clipped');
        $this->assertFalse($resGood->hasHum, 'Clean audio must not have hum');
        $this->assertTrue($resGood->snrDb > 15.0, 'Clean audio must have high SNR');

        // Clipped file
        $clippedPath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'clipped.wav';
        $samples = array_fill(0, 22050, 1.0); // 1.0 constant saturated
        WavProcessor::writeWav($clippedPath, $samples, 22050);
        $resClipped = $gate->evaluate($clippedPath);
        $this->assertFalse($resClipped->passed, 'Clipped audio must fail quality gate');
        $this->assertTrue($resClipped->isClipped, 'Clipping must be detected');

        // Mains Hum file (50Hz tone dominates)
        $humPath = $this->testTmpDir . DIRECTORY_SEPARATOR . 'hum.wav';
        $humSamples = [];
        for ($i = 0; $i < 22050 * 2; $i++) {
            $t = (float)$i / 22050;
            // 50Hz mains hum
            $humSamples[] = sin(2 * M_PI * 50 * $t) * 0.8;
        }
        WavProcessor::writeWav($humPath, $humSamples, 22050);
        $resHum = $gate->evaluate($humPath);
        $this->assertTrue($resHum->hasHum, '50Hz mains hum must be detected');
    }

    public function testLjSpeechExporterAndSplits(): void
    {
        $exportDir = $this->testTmpDir . DIRECTORY_SEPARATOR . 'dataset_export';

        $segments = [];
        for ($i = 1; $i <= 10; $i++) {
            $id = sprintf('LJ001_%04d', $i);
            $segments[] = new AudioSegment(
                id: $id,
                sourceFile: 'audio.wav',
                startSec: ($i - 1) * 3.0,
                endSec: $i * 3.0,
                durationSec: 3.0,
                outputPath: $exportDir . "/wavs/{$id}.wav",
                text: "This is test utterance number {$i}.",
                normalizedText: "This is test utterance number {$i}."
            );
        }

        $exporter = new LjSpeechExporter(trainRatio: 0.80);
        $res = $exporter->export($segments, $exportDir);

        $this->assertEquals(10, $res['total_segments'], 'Total segment count mismatch');
        $this->assertEquals(8, $res['train_count'], 'Train count mismatch (80% of 10)');
        $this->assertEquals(2, $res['val_count'], 'Validation count mismatch (20% of 10)');

        $this->assertTrue(file_exists($res['metadata_path']), 'metadata.csv must exist');
        $this->assertTrue(file_exists($res['train_path']), 'train.csv must exist');
        $this->assertTrue(file_exists($res['val_path']), 'val.csv must exist');
        $this->assertTrue(file_exists($res['stats_path']), 'stats.json must exist');

        // Check metadata formatting: ID|Text|NormalizedText
        $lines = file($res['metadata_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertEquals(10, count($lines), 'metadata.csv must contain 10 rows');
        $parts = explode('|', $lines[0]);
        $this->assertEquals(3, count($parts), 'LJSpeech metadata row must have exactly 3 columns');
        $this->assertEquals('LJ001_0001', $parts[0], 'First row ID mismatch');

        // Check stats.json
        $stats = json_decode(file_get_contents($res['stats_path']), true);
        $this->assertEquals(10, $stats['total_samples'], 'Stats sample count mismatch');
        $this->assertEquals(30.0, $stats['total_duration_sec'], 'Stats duration mismatch');
    }

    public function testEndToEndDatasetFactoryBuildAndAudit(): void
    {
        $rawDir = $this->testTmpDir . DIRECTORY_SEPARATOR . 'raw_audio';
        $outDir = $this->testTmpDir . DIRECTORY_SEPARATOR . 'final_dataset';
        mkdir($rawDir, 0777, true);

        // Create 2 raw audio files: one with 2 pauses (approx 8 seconds)
        $raw1 = $rawDir . DIRECTORY_SEPARATOR . 'recording_01.wav';
        $this->generateSyntheticWav($raw1, durationSec: 6.0, amplitude: 0.5, silenceLeadingSec: 0.3, silenceTrailingSec: 0.3);

        $raw2 = $rawDir . DIRECTORY_SEPARATOR . 'recording_02.wav';
        $this->generateSyntheticWav($raw2, durationSec: 5.0, amplitude: 0.6, silenceLeadingSec: 0.2, silenceTrailingSec: 0.2);

        $factory = new DatasetFactory([
            'target_lufs' => -23.0,
            'min_segment_sec' => 2.0,
            'max_segment_sec' => 10.0,
            'min_snr_db' => 12.0,
            'train_ratio' => 0.75,
        ]);

        $buildResult = $factory->build(
            rawAudioDir: $rawDir,
            outputDir: $outDir,
            transcripts: [
                'recording_01' => 'The quick brown fox jumps over the lazy dog.',
                'recording_02' => 'Speech AI datasets require calibrated acoustic segments.'
            ]
        );

        $this->assertEquals('success', $buildResult['status'], 'Build status must be success');
        $this->assertTrue($buildResult['accepted'] >= 2, 'Must accept at least 2 segments');

        // Audit the generated dataset
        $auditResult = $factory->audit($outDir, strict: true);
        $this->assertTrue($auditResult['passed'], 'Generated dataset must pass strict audit');
        $this->assertEquals(0, $auditResult['stats']['failed_files'], 'Failed files must be 0');
        $this->assertTrue($auditResult['stats']['average_snr_db'] > 12.0, 'Audit average SNR must exceed threshold');
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->cleanDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function assertTrue(bool $condition, string $msg): void
    {
        if (!$condition) {
            throw new \AssertionError("ASSERTION FAILED: {$msg}");
        }
    }

    private function assertFalse(bool $condition, string $msg): void
    {
        $this->assertTrue(!$condition, $msg);
    }

    private function assertEquals(mixed $expected, mixed $actual, string $msg): void
    {
        if ($expected != $actual) {
            throw new \AssertionError("ASSERTION FAILED: {$msg} | Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
        }
    }

    private function assertNear(float $expected, float $actual, float $delta, string $msg): void
    {
        if (abs($expected - $actual) > $delta) {
            throw new \AssertionError("ASSERTION FAILED: {$msg} | Expected {$expected} +- {$delta}, got {$actual}");
        }
    }
}
