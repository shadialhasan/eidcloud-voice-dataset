<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset\Audio;

use RuntimeException;

/**
 * Voice Activity Detection (VAD) & aggressive silence trimming.
 * Supports FFmpeg silenceremove filter with pure PHP RMS-based fallback.
 */
class SilenceTrimmer
{
    private bool $hasFFmpeg;

    public function __construct(
        private readonly float $silenceThresholdDb = -45.0,
        private readonly float $minSilenceDurationSec = 0.25,
        private readonly float $paddingSec = 0.05
    ) {
        $this->hasFFmpeg = $this->detectFFmpeg();
    }

    /**
     * Check if FFmpeg CLI is accessible.
     */
    public function hasFFmpeg(): bool
    {
        return $this->hasFFmpeg;
    }

    /**
     * Trim leading and trailing silences from an audio file.
     *
     * @param string $inputPath Path to source WAV/audio
     * @param string $outputPath Path to save trimmed audio
     * @return array{
     *     original_duration: float,
     *     trimmed_duration: float,
     *     start_trimmed_sec: float,
     *     end_trimmed_sec: float
     * }
     */
    public function trim(string $inputPath, string $outputPath): array
    {
        if (!file_exists($inputPath)) {
            throw new RuntimeException("Input file does not exist: {$inputPath}");
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if ($this->hasFFmpeg) {
            try {
                return $this->trimWithFFmpeg($inputPath, $outputPath);
            } catch (\Throwable $e) {
                // Fallback to pure PHP on any FFmpeg execution error
            }
        }

        return $this->trimWithPurePhp($inputPath, $outputPath);
    }

    /**
     * Trim silence using FFmpeg silenceremove.
     */
    private function trimWithFFmpeg(string $inputPath, string $outputPath): array
    {
        // 1. Get original duration
        $origDuration = $this->getFFmpegDuration($inputPath);

        // silenceremove=start_periods=1:start_duration=0.05:start_threshold=-45dB:areverse,silenceremove=start_periods=1:start_duration=0.05:start_threshold=-45dB,areverse
        $threshStr = sprintf('%.1fdB', $this->silenceThresholdDb);
        $minDurStr = sprintf('%.2f', $this->minSilenceDurationSec);

        $filter = sprintf(
            'silenceremove=start_periods=1:start_duration=%s:start_threshold=%s,areverse,silenceremove=start_periods=1:start_duration=%s:start_threshold=%s,areverse',
            $minDurStr,
            $threshStr,
            $minDurStr,
            $threshStr
        );

        $cmd = sprintf(
            'ffmpeg -y -v error -i %s -af %s -c:a pcm_s16le %s 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($filter),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $returnCode);
        if ($returnCode !== 0 || !file_exists($outputPath) || filesize($outputPath) < 44) {
            throw new RuntimeException("FFmpeg silence trimming failed: " . implode("\n", $output));
        }

        $trimmedDuration = $this->getFFmpegDuration($outputPath);

        return [
            'original_duration' => round($origDuration, 3),
            'trimmed_duration' => round($trimmedDuration, 3),
            'start_trimmed_sec' => 0.0, // Aggregated by filter
            'end_trimmed_sec' => round(max(0.0, $origDuration - $trimmedDuration), 3),
        ];
    }

    /**
     * Pure PHP silence trimming based on RMS frame windowing.
     */
    public function trimWithPurePhp(string $inputPath, string $outputPath): array
    {
        $samples = WavProcessor::readNormalizedSamples($inputPath);
        $header = WavProcessor::readHeader($inputPath);
        $sampleRate = $header['sampleRate'];
        $numSamples = count($samples);

        if ($numSamples === 0) {
            WavProcessor::writeWav($outputPath, [], $sampleRate);
            return [
                'original_duration' => 0.0,
                'trimmed_duration' => 0.0,
                'start_trimmed_sec' => 0.0,
                'end_trimmed_sec' => 0.0,
            ];
        }

        $origDuration = (float)$numSamples / $sampleRate;
        $frameSize = (int)($sampleRate * 0.02); // 20ms frame
        if ($frameSize < 1) {
            $frameSize = 1;
        }

        $thresholdLin = pow(10, $this->silenceThresholdDb / 20);

        // Find start sample where energy exceeds threshold
        $startIdx = 0;
        for ($i = 0; $i < $numSamples; $i += $frameSize) {
            $end = min($numSamples, $i + $frameSize);
            $sumSq = 0.0;
            for ($k = $i; $k < $end; $k++) {
                $sumSq += $samples[$k] * $samples[$k];
            }
            $rms = sqrt($sumSq / max(1, $end - $i));
            if ($rms > $thresholdLin) {
                $startIdx = max(0, $i - (int)($this->paddingSec * $sampleRate));
                break;
            }
        }

        // Find end sample where energy exceeds threshold from behind
        $endIdx = $numSamples;
        for ($i = $numSamples; $i > 0; $i -= $frameSize) {
            $start = max(0, $i - $frameSize);
            $sumSq = 0.0;
            for ($k = $start; $k < $i; $k++) {
                $sumSq += $samples[$k] * $samples[$k];
            }
            $rms = sqrt($sumSq / max(1, $i - $start));
            if ($rms > $thresholdLin) {
                $endIdx = min($numSamples, $i + (int)($this->paddingSec * $sampleRate));
                break;
            }
        }

        if ($startIdx >= $endIdx) {
            // Entire audio was silent
            $trimmedSamples = [];
        } else {
            $trimmedSamples = array_slice($samples, $startIdx, $endIdx - $startIdx);
        }

        WavProcessor::writeWav($outputPath, $trimmedSamples, $sampleRate);

        $startTrimmedSec = (float)$startIdx / $sampleRate;
        $trimmedDuration = (float)count($trimmedSamples) / $sampleRate;
        $endTrimmedSec = (float)($numSamples - $endIdx) / $sampleRate;

        return [
            'original_duration' => round($origDuration, 3),
            'trimmed_duration' => round($trimmedDuration, 3),
            'start_trimmed_sec' => round($startTrimmedSec, 3),
            'end_trimmed_sec' => round($endTrimmedSec, 3),
        ];
    }

    private function getFFmpegDuration(string $path): float
    {
        $cmd = sprintf('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1', escapeshellarg($path));
        $val = trim((string)shell_exec($cmd));
        if (is_numeric($val)) {
            return (float)$val;
        }

        try {
            $h = WavProcessor::readHeader($path);
            return $h['durationSec'];
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function detectFFmpeg(): bool
    {
        $cmd = DIRECTORY_SEPARATOR === '\\' ? 'where ffmpeg 2>nul' : 'which ffmpeg 2>/dev/null';
        $out = shell_exec($cmd);
        return !empty(trim((string)$out));
    }
}
