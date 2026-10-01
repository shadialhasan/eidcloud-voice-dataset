<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset\Audio;

use RuntimeException;

/**
 * Splits continuous voice recordings on natural pauses / silence intervals
 * constrained between minSegmentSec (default 2.0s) and maxSegmentSec (default 12.0s).
 * Ideal for XTTS, Bark, FastSpeech, VITS, and Whisper dataset prep.
 */
class SmartSegmenter
{
    public function __construct(
        private readonly float $minSegmentSec = 2.0,
        private readonly float $maxSegmentSec = 12.0,
        private readonly float $silenceThresholdDb = -40.0,
        private readonly float $pauseDurationSec = 0.35,
        private readonly float $paddingSec = 0.08
    ) {
    }

    /**
     * Detect split boundaries (start, end) in seconds.
     *
     * @return array<array{start: float, end: float, duration: float}>
     */
    public function detectSegments(string $audioPath): array
    {
        $samples = WavProcessor::readNormalizedSamples($audioPath);
        $header = WavProcessor::readHeader($audioPath);
        $sampleRate = $header['sampleRate'];
        $numSamples = count($samples);

        if ($numSamples === 0) {
            return [];
        }

        $totalDuration = (float)$numSamples / $sampleRate;
        if ($totalDuration <= $this->maxSegmentSec && $totalDuration >= $this->minSegmentSec) {
            return [
                [
                    'start' => 0.0,
                    'end' => round($totalDuration, 3),
                    'duration' => round($totalDuration, 3),
                ]
            ];
        }

        // Frame analysis: 25ms windows with 10ms hop
        $frameLength = (int)($sampleRate * 0.025);
        $hopLength = (int)($sampleRate * 0.010);
        $thresholdLin = pow(10, $this->silenceThresholdDb / 20);

        // Find silence regions
        $isSilent = [];
        for ($pos = 0; $pos < $numSamples; $pos += $hopLength) {
            $end = min($numSamples, $pos + $frameLength);
            $sumSq = 0.0;
            for ($k = $pos; $k < $end; $k++) {
                $sumSq += $samples[$k] * $samples[$k];
            }
            $rms = sqrt($sumSq / max(1, $end - $pos));
            $isSilent[] = ($rms < $thresholdLin);
        }

        $frameCount = count($isSilent);
        $minPauseFrames = (int)($this->pauseDurationSec / 0.010);

        // Group silence frames into pause intervals [startSec, endSec]
        $pauses = [];
        $inPause = false;
        $pauseStart = 0;

        for ($f = 0; $f < $frameCount; $f++) {
            if ($isSilent[$f]) {
                if (!$inPause) {
                    $inPause = true;
                    $pauseStart = $f;
                }
            } else {
                if ($inPause) {
                    $inPause = false;
                    $pauseLen = $f - $pauseStart;
                    if ($pauseLen >= $minPauseFrames) {
                        $pauses[] = [
                            'start' => $pauseStart * 0.010,
                            'end' => $f * 0.010,
                            'mid' => ($pauseStart + $f) * 0.5 * 0.010,
                        ];
                    }
                }
            }
        }
        if ($inPause && ($frameCount - $pauseStart) >= $minPauseFrames) {
            $pauses[] = [
                'start' => $pauseStart * 0.010,
                'end' => $frameCount * 0.010,
                'mid' => ($pauseStart + $frameCount) * 0.5 * 0.010,
            ];
        }

        // Segment splitting based on target durations
        $segments = [];
        $currentStart = 0.0;

        foreach ($pauses as $pause) {
            $midPoint = $pause['mid'];
            $segDuration = $midPoint - $currentStart;

            if ($segDuration >= $this->minSegmentSec) {
                if ($segDuration <= $this->maxSegmentSec) {
                    $segEnd = min($totalDuration, $pause['start'] + $this->paddingSec);
                    $segments[] = [
                        'start' => round($currentStart, 3),
                        'end' => round($segEnd, 3),
                        'duration' => round($segEnd - $currentStart, 3),
                    ];
                    $currentStart = max(0.0, $pause['end'] - $this->paddingSec);
                } else {
                    // Segment grew too long before a pause. Subdivide hard or at next pause
                    $subStart = $currentStart;
                    while (($midPoint - $subStart) > $this->maxSegmentSec) {
                        $subEnd = $subStart + $this->maxSegmentSec;
                        $segments[] = [
                            'start' => round($subStart, 3),
                            'end' => round($subEnd, 3),
                            'duration' => round($subEnd - $subStart, 3),
                        ];
                        $subStart = $subEnd;
                    }
                    if (($midPoint - $subStart) >= $this->minSegmentSec) {
                        $segments[] = [
                            'start' => round($subStart, 3),
                            'end' => round($midPoint, 3),
                            'duration' => round($midPoint - $subStart, 3),
                        ];
                    }
                    $currentStart = max(0.0, $pause['end'] - $this->paddingSec);
                }
            }
        }

        // Handle remainder
        if (($totalDuration - $currentStart) >= $this->minSegmentSec) {
            $rem = $totalDuration - $currentStart;
            if ($rem <= $this->maxSegmentSec) {
                $segments[] = [
                    'start' => round($currentStart, 3),
                    'end' => round($totalDuration, 3),
                    'duration' => round($totalDuration - $currentStart, 3),
                ];
            } else {
                $subStart = $currentStart;
                while ($subStart < $totalDuration) {
                    $subEnd = min($totalDuration, $subStart + $this->maxSegmentSec);
                    if (($subEnd - $subStart) >= $this->minSegmentSec) {
                        $segments[] = [
                            'start' => round($subStart, 3),
                            'end' => round($subEnd, 3),
                            'duration' => round($subEnd - $subStart, 3),
                        ];
                    }
                    $subStart = $subEnd;
                }
            }
        } elseif (empty($segments) && $totalDuration > 0.5) {
            // Force single segment if overall non-trivial
            $segments[] = [
                'start' => 0.0,
                'end' => round($totalDuration, 3),
                'duration' => round($totalDuration, 3),
            ];
        }

        return $segments;
    }

    /**
     * Slice audio file into segments and export individual WAVs.
     *
     * @param string $sourcePath Path to source audio file
     * @param string $outputDir Directory where segments will be saved
     * @param string $prefix Prefix for segment IDs (e.g. "LJ001")
     * @return array<array{id: string, path: string, start: float, end: float, duration: float}>
     */
    public function segmentAndSave(string $sourcePath, string $outputDir, string $prefix): array
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $detected = $this->detectSegments($sourcePath);
        $results = [];
        $index = 1;

        foreach ($detected as $seg) {
            $segId = sprintf('%s_%04d', $prefix, $index++);
            $outPath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segId . '.wav';

            WavProcessor::sliceWav($sourcePath, $outPath, $seg['start'], $seg['end']);

            $results[] = [
                'id' => $segId,
                'path' => $outPath,
                'start' => $seg['start'],
                'end' => $seg['end'],
                'duration' => $seg['duration'],
            ];
        }

        return $results;
    }
}
