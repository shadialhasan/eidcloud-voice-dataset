<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset\Quality;

use EidCloud\VoiceDataset\Audio\WavProcessor;
use EidCloud\VoiceDataset\Model\QualityResult;

/**
 * Audio Quality Gate.
 * Evaluates Signal-to-Noise Ratio (SNR), digital clipping, and mains background hum (50Hz / 60Hz).
 */
class QualityGate
{
    public function __construct(
        private readonly float $minSnrDb = 15.0,
        private readonly float $clippingThresholdDbfs = -0.1,
        private readonly int $maxConsecutiveClippedSamples = 3,
        private readonly float $maxHumRatioDb = 12.0
    ) {
    }

    /**
     * Inspect an audio file and evaluate quality criteria.
     */
    public function evaluate(string $filePath): QualityResult
    {
        $samples = WavProcessor::readNormalizedSamples($filePath);
        $header = WavProcessor::readHeader($filePath);
        $sampleRate = $header['sampleRate'];
        $numSamples = count($samples);

        if ($numSamples === 0) {
            return new QualityResult(
                passed: false,
                snrDb: 0.0,
                peakDbfs: -100.0,
                isClipped: false,
                hasHum: false,
                reasons: ['Audio file contains 0 samples'],
                metrics: []
            );
        }

        // 1. Peak & Clipping detection
        $peak = 0.0;
        $consecutiveClipped = 0;
        $maxConsecutiveSeen = 0;
        $clippingLin = pow(10, $this->clippingThresholdDbfs / 20);

        foreach ($samples as $s) {
            $abs = abs($s);
            if ($abs > $peak) {
                $peak = $abs;
            }
            if ($abs >= $clippingLin) {
                $consecutiveClipped++;
                if ($consecutiveClipped > $maxConsecutiveSeen) {
                    $maxConsecutiveSeen = $consecutiveClipped;
                }
            } else {
                $consecutiveClipped = 0;
            }
        }

        $peakDbfs = 20 * log10(max(1e-6, $peak));
        $isClipped = ($maxConsecutiveSeen >= $this->maxConsecutiveClippedSamples);

        // 2. SNR calculation via 20ms frame energy distribution
        // Active speech vs noise floor estimation
        $frameSize = (int)($sampleRate * 0.02);
        if ($frameSize < 1) {
            $frameSize = 1;
        }

        $frameEnergies = [];
        for ($i = 0; $i < $numSamples; $i += $frameSize) {
            $end = min($numSamples, $i + $frameSize);
            $sumSq = 0.0;
            for ($k = $i; $k < $end; $k++) {
                $sumSq += $samples[$k] * $samples[$k];
            }
            $rms = sqrt($sumSq / max(1, $end - $i));
            $frameEnergies[] = max(1e-7, $rms);
        }

        // Active speech vs noise floor estimation:
        // Speech frames are typically within 20dB of the loudest frames.
        // Noise floor is estimated from low-energy quiet frames, or a minimum calibrated silence floor.
        $maxEnergy = end($frameEnergies);
        $speechThreshold = $maxEnergy * 0.1; // -20dB below peak frame

        $speechFrames = [];
        $noiseFrames = [];
        foreach ($frameEnergies as $energy) {
            if ($energy >= $speechThreshold) {
                $speechFrames[] = $energy;
            } else {
                $noiseFrames[] = $energy;
            }
        }

        if (!empty($speechFrames)) {
            $speechRms = array_sum($speechFrames) / count($speechFrames);
        } else {
            $speechRms = $maxEnergy;
        }

        if (!empty($noiseFrames)) {
            $noiseRms = array_sum($noiseFrames) / count($noiseFrames);
        } else {
            // When tightly trimmed, silence is eliminated, so noise floor is minimal (quantization/residual floor)
            $noiseRms = min($frameEnergies[0], 0.001);
        }

        $snrDb = 20 * log10(max(1e-6, $speechRms) / max(1e-6, $noiseRms));

        // 3. Background Hum Detection (50Hz / 60Hz mains hum)
        // Using Goertzel algorithm to detect energy at 50Hz and 60Hz relative to neighboring bands
        $humDetected = $this->detectMainsHum($samples, $sampleRate);

        $reasons = [];
        if ($isClipped) {
            $reasons[] = sprintf('Audio is clipped (peak: %.2f dBFS, consecutive saturated samples: %d)', $peakDbfs, $maxConsecutiveSeen);
        }
        if ($snrDb < $this->minSnrDb) {
            $reasons[] = sprintf('SNR below threshold: %.2f dB (minimum: %.2f dB)', $snrDb, $this->minSnrDb);
        }
        if ($humDetected['detected']) {
            $reasons[] = sprintf('Mains hum detected at %d Hz (+%.1f dB above ambient)', $humDetected['freq'], $humDetected['prominence']);
        }

        $passed = empty($reasons);

        return new QualityResult(
            passed: $passed,
            snrDb: $snrDb,
            peakDbfs: $peakDbfs,
            isClipped: $isClipped,
            hasHum: $humDetected['detected'],
            reasons: $reasons,
            metrics: [
                'noise_floor_rms' => round($noiseRms, 5),
                'speech_level_rms' => round($speechRms, 5),
                'hum_metrics' => $humDetected,
                'clipping_run' => $maxConsecutiveSeen,
            ]
        );
    }

    /**
     * Mains hum detection using Goertzel algorithm at target frequencies.
     *
     * @param array<float> $samples
     * @return array{detected: bool, freq: int, prominence: float}
     */
    private function detectMainsHum(array $samples, int $sampleRate): array
    {
        $testFreqs = [50, 60, 100, 120];
        $referenceFreqs = [35, 75, 140];

        $targetPowers = [];
        foreach ($testFreqs as $f) {
            $targetPowers[$f] = $this->goertzelPower($samples, $sampleRate, (float)$f);
        }

        $refPowers = [];
        foreach ($referenceFreqs as $f) {
            $refPowers[] = $this->goertzelPower($samples, $sampleRate, (float)$f);
        }

        $avgRefPower = max(1e-12, array_sum($refPowers) / max(1, count($refPowers)));

        $maxProminence = 0.0;
        $maxFreq = 50;
        $detected = false;

        foreach ($targetPowers as $freq => $p) {
            $ratioDb = 10 * log10(max(1e-12, $p) / $avgRefPower);
            if ($ratioDb > $this->maxHumRatioDb && $p > 1e-6) {
                $detected = true;
                if ($ratioDb > $maxProminence) {
                    $maxProminence = $ratioDb;
                    $maxFreq = $freq;
                }
            }
        }

        return [
            'detected' => $detected,
            'freq' => $maxFreq,
            'prominence' => round($maxProminence, 1),
        ];
    }

    /**
     * Optimized Goertzel algorithm for single frequency energy detection.
     *
     * @param array<float> $samples
     */
    private function goertzelPower(array $samples, int $sampleRate, float $targetFreq): float
    {
        $n = count($samples);
        if ($n === 0) {
            return 0.0;
        }

        // Downsample or limit sample window to 1-2 seconds for speed
        $limit = min($n, $sampleRate * 2);
        $k = (int)(0.5 + ($limit * $targetFreq / $sampleRate));
        $omega = (2.0 * M_PI * $k) / $limit;
        $coeff = 2.0 * cos($omega);

        $s0 = 0.0;
        $s1 = 0.0;
        $s2 = 0.0;

        for ($i = 0; $i < $limit; $i++) {
            $s0 = $samples[$i] + $coeff * $s1 - $s2;
            $s2 = $s1;
            $s1 = $s0;
        }

        return ($s1 * $s1 + $s2 * $s2 - $coeff * $s1 * $s2) / $limit;
    }
}
