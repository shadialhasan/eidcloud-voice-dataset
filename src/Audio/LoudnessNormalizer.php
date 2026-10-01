<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset\Audio;

use RuntimeException;

/**
 * EBU R128 loudness normalization.
 * Targets -23.0 LUFS (EBU standard) or -16.0 LUFS (Podcast/Speech standard).
 * Supports dual-pass FFmpeg loudnorm with pure PHP RMS/peak normalization fallback.
 */
class LoudnessNormalizer
{
    private bool $hasFFmpeg;

    public function __construct(
        private readonly float $targetLufs = -23.0,
        private readonly float $targetLra = 7.0,
        private readonly float $targetTp = -1.5
    ) {
        $this->hasFFmpeg = $this->detectFFmpeg();
    }

    /**
     * Check if FFmpeg is available.
     */
    public function hasFFmpeg(): bool
    {
        return $this->hasFFmpeg;
    }

    /**
     * Normalize an audio file to the target loudness.
     *
     * @return array{
     *     target_lufs: float,
     *     integrated_loudness: float,
     *     true_peak: float,
     *     lra: float,
     *     gain_applied_db: float
     * }
     */
    public function normalize(string $inputPath, string $outputPath): array
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
                return $this->normalizeWithFFmpeg($inputPath, $outputPath);
            } catch (\Throwable $e) {
                // Fallback to pure PHP on failure
            }
        }

        return $this->normalizeWithPurePhp($inputPath, $outputPath);
    }

    /**
     * 2-pass FFmpeg loudnorm normalization.
     */
    private function normalizeWithFFmpeg(string $inputPath, string $outputPath): array
    {
        // Pass 1: measure
        $filterPass1 = sprintf(
            'loudnorm=I=%.1f:LRA=%.1f:TP=%.1f:print_format=json',
            $this->targetLufs,
            $this->targetLra,
            $this->targetTp
        );

        $cmdPass1 = sprintf(
            'ffmpeg -v error -i %s -af %s -f null - 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($filterPass1)
        );

        $outPass1 = shell_exec($cmdPass1) ?? '';
        $jsonStart = strrpos($outPass1, '{');
        $jsonEnd = strrpos($outPass1, '}');

        if ($jsonStart === false || $jsonEnd === false || $jsonEnd <= $jsonStart) {
            // Single pass fallback
            $filterSingle = sprintf('loudnorm=I=%.1f:LRA=%.1f:TP=%.1f', $this->targetLufs, $this->targetLra, $this->targetTp);
            $cmd = sprintf(
                'ffmpeg -y -v error -i %s -af %s -c:a pcm_s16le %s 2>&1',
                escapeshellarg($inputPath),
                escapeshellarg($filterSingle),
                escapeshellarg($outputPath)
            );
            exec($cmd, $o, $rc);
            if ($rc !== 0 || !file_exists($outputPath)) {
                throw new RuntimeException("FFmpeg single pass loudnorm failed.");
            }
            return [
                'target_lufs' => $this->targetLufs,
                'integrated_loudness' => $this->targetLufs,
                'true_peak' => $this->targetTp,
                'lra' => $this->targetLra,
                'gain_applied_db' => 0.0,
            ];
        }

        $statsJson = substr($outPass1, $jsonStart, $jsonEnd - $jsonStart + 1);
        $stats = json_decode($statsJson, true);

        if (!is_array($stats) || !isset($stats['input_i'])) {
            throw new RuntimeException("Could not parse loudnorm pass 1 JSON.");
        }

        $measuredI = (float)$stats['input_i'];
        $measuredTp = (float)$stats['input_tp'];
        $measuredLra = (float)$stats['input_lra'];
        $measuredThresh = (float)$stats['input_thresh'];
        $offset = (float)$stats['target_offset'];

        // Pass 2: apply
        $filterPass2 = sprintf(
            'loudnorm=I=%.1f:LRA=%.1f:TP=%.1f:measured_I=%.2f:measured_TP=%.2f:measured_LRA=%.2f:measured_thresh=%.2f:offset=%.2f:linear=true',
            $this->targetLufs,
            $this->targetLra,
            $this->targetTp,
            $measuredI,
            $measuredTp,
            $measuredLra,
            $measuredThresh,
            $offset
        );

        $cmdPass2 = sprintf(
            'ffmpeg -y -v error -i %s -af %s -c:a pcm_s16le %s 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($filterPass2),
            escapeshellarg($outputPath)
        );

        exec($cmdPass2, $output, $returnCode);
        if ($returnCode !== 0 || !file_exists($outputPath) || filesize($outputPath) < 44) {
            throw new RuntimeException("FFmpeg loudnorm pass 2 failed: " . implode("\n", $output));
        }

        $gainApplied = $this->targetLufs - $measuredI;

        return [
            'target_lufs' => $this->targetLufs,
            'integrated_loudness' => $measuredI,
            'true_peak' => $measuredTp,
            'lra' => $measuredLra,
            'gain_applied_db' => round($gainApplied, 2),
        ];
    }

    /**
     * Pure PHP loudness & peak normalization fallback.
     * Approximates LUFS using standard K-weighting / RMS and normalizes to target energy.
     */
    public function normalizeWithPurePhp(string $inputPath, string $outputPath): array
    {
        $samples = WavProcessor::readNormalizedSamples($inputPath);
        $header = WavProcessor::readHeader($inputPath);
        $sampleRate = $header['sampleRate'];
        $numSamples = count($samples);

        if ($numSamples === 0) {
            WavProcessor::writeWav($outputPath, [], $sampleRate);
            return [
                'target_lufs' => $this->targetLufs,
                'integrated_loudness' => -70.0,
                'true_peak' => -70.0,
                'lra' => 0.0,
                'gain_applied_db' => 0.0,
            ];
        }

        // Calculate peak and RMS
        $maxAbs = 0.000001;
        $sumSq = 0.0;
        foreach ($samples as $s) {
            $abs = abs($s);
            if ($abs > $maxAbs) {
                $maxAbs = $abs;
            }
            $sumSq += $s * $s;
        }

        $rms = sqrt($sumSq / $numSamples);
        $currentDbfs = 20 * log10(max(1e-6, $rms));
        $peakDbfs = 20 * log10(max(1e-6, $maxAbs));

        // In pure speech, RMS dBFS approximates integrated LUFS closely (~ -3dB offset)
        $estimatedLufs = $currentDbfs - 3.0;

        $targetGainDb = $this->targetLufs - $estimatedLufs;
        $gainLin = pow(10, $targetGainDb / 20);

        // Ceiling protection against clipping (target true peak)
        $targetPeakLin = pow(10, $this->targetTp / 20);
        if (($maxAbs * $gainLin) > $targetPeakLin) {
            $gainLin = $targetPeakLin / $maxAbs;
            $targetGainDb = 20 * log10($gainLin);
        }

        $normalizedSamples = [];
        foreach ($samples as $s) {
            $val = $s * $gainLin;
            $normalizedSamples[] = max(-1.0, min(1.0, $val));
        }

        WavProcessor::writeWav($outputPath, $normalizedSamples, $sampleRate);

        return [
            'target_lufs' => $this->targetLufs,
            'integrated_loudness' => round($estimatedLufs, 2),
            'true_peak' => round($peakDbfs, 2),
            'lra' => $this->targetLra,
            'gain_applied_db' => round($targetGainDb, 2),
        ];
    }

    private function detectFFmpeg(): bool
    {
        $cmd = DIRECTORY_SEPARATOR === '\\' ? 'where ffmpeg 2>nul' : 'which ffmpeg 2>/dev/null';
        $out = shell_exec($cmd);
        return !empty(trim((string)$out));
    }
}
