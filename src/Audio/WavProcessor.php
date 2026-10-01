<?php

declare(strict_types=1);

namespace EidCloud\VoiceDataset\Audio;

use RuntimeException;

/**
 * Low-level WAV file reader and writer for 16-bit PCM WAV files.
 * Provides pure PHP parsing, header rewriting, sample extraction, and windowing.
 */
class WavProcessor
{
    /**
     * Parse WAV header information.
     *
     * @return array{
     *     channels: int,
     *     sampleRate: int,
     *     byteRate: int,
     *     blockAlign: int,
     *     bitsPerSample: int,
     *     dataOffset: int,
     *     dataSize: int,
     *     totalSamples: int,
     *     durationSec: float
     * }
     */
    public static function readHeader(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("WAV file not found or unreadable: {$filePath}");
        }

        $fp = fopen($filePath, 'rb');
        if (!$fp) {
            throw new RuntimeException("Could not open file: {$filePath}");
        }

        try {
            $riff = fread($fp, 4);
            if ($riff !== 'RIFF') {
                throw new RuntimeException("Invalid RIFF header in {$filePath}");
            }

            fseek($fp, 4, SEEK_CUR); // Skip fileSize
            $wave = fread($fp, 4);
            if ($wave !== 'WAVE') {
                throw new RuntimeException("Invalid WAVE format in {$filePath}");
            }

            $fmtFound = false;
            $channels = 1;
            $sampleRate = 44100;
            $byteRate = 88200;
            $blockAlign = 2;
            $bitsPerSample = 16;
            $dataOffset = 0;
            $dataSize = 0;

            while (!feof($fp)) {
                $chunkId = fread($fp, 4);
                if (strlen($chunkId) < 4) {
                    break;
                }
                $chunkSizeData = fread($fp, 4);
                if (strlen($chunkSizeData) < 4) {
                    break;
                }
                $chunkSize = unpack('V', $chunkSizeData)[1];

                if ($chunkId === 'fmt ') {
                    $fmtFound = true;
                    $fmtData = fread($fp, $chunkSize);
                    $fmt = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', $fmtData);
                    $channels = $fmt['channels'];
                    $sampleRate = $fmt['sampleRate'];
                    $byteRate = $fmt['byteRate'];
                    $blockAlign = $fmt['blockAlign'];
                    $bitsPerSample = $fmt['bitsPerSample'];
                } elseif ($chunkId === 'data') {
                    $dataOffset = ftell($fp);
                    $dataSize = $chunkSize;
                    break;
                } else {
                    fseek($fp, $chunkSize, SEEK_CUR);
                }
            }

            if (!$fmtFound || $dataOffset === 0) {
                throw new RuntimeException("Corrupted or unsupported WAV file: missing fmt or data chunk.");
            }

            $bytesPerSample = max(1, (int)($bitsPerSample / 8));
            $totalSamples = (int)($dataSize / ($channels * $bytesPerSample));
            $durationSec = $sampleRate > 0 ? (float)$totalSamples / $sampleRate : 0.0;

            return [
                'channels' => $channels,
                'sampleRate' => $sampleRate,
                'byteRate' => $byteRate,
                'blockAlign' => $blockAlign,
                'bitsPerSample' => $bitsPerSample,
                'dataOffset' => $dataOffset,
                'dataSize' => $dataSize,
                'totalSamples' => $totalSamples,
                'durationSec' => $durationSec,
            ];
        } finally {
            fclose($fp);
        }
    }

    /**
     * Read 16-bit PCM audio samples as normalized floats between -1.0 and 1.0.
     * If multi-channel, downmixes to mono.
     *
     * @return array<float>
     */
    public static function readNormalizedSamples(string $filePath): array
    {
        $header = self::readHeader($filePath);
        if ($header['bitsPerSample'] !== 16) {
            throw new RuntimeException("Only 16-bit PCM WAV is supported for pure-PHP sample decoding.");
        }

        $fp = fopen($filePath, 'rb');
        if (!$fp) {
            throw new RuntimeException("Could not open file: {$filePath}");
        }

        try {
            fseek($fp, $header['dataOffset']);
            $raw = fread($fp, $header['dataSize']);
            $count = (int)(strlen($raw) / 2);
            if ($count <= 0) {
                return [];
            }

            // 's*' unpacks signed 16-bit in machine byte order, but WAV is little endian.
            // On x86/x64 it is little-endian.
            $unpacked = unpack('s*', $raw);
            $samples = [];
            $channels = $header['channels'];

            if ($channels === 1) {
                foreach ($unpacked as $val) {
                    $samples[] = $val / 32768.0;
                }
            } else {
                $len = count($unpacked);
                for ($i = 1; $i <= $len; $i += $channels) {
                    $sum = 0.0;
                    for ($c = 0; $c < $channels; $c++) {
                        if (isset($unpacked[$i + $c])) {
                            $sum += $unpacked[$i + $c];
                        }
                    }
                    $samples[] = ($sum / $channels) / 32768.0;
                }
            }

            return $samples;
        } finally {
            fclose($fp);
        }
    }

    /**
     * Write raw float samples (-1.0 to 1.0) into a 16-bit mono PCM WAV file.
     *
     * @param array<float> $samples
     */
    public static function writeWav(string $outputPath, array $samples, int $sampleRate = 22050): void
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $numSamples = count($samples);
        $numChannels = 1;
        $bitsPerSample = 16;
        $bytesPerSample = 2;
        $byteRate = $sampleRate * $numChannels * $bytesPerSample;
        $blockAlign = $numChannels * $bytesPerSample;
        $dataSize = $numSamples * $numChannels * $bytesPerSample;
        $riffSize = 36 + $dataSize;

        $fp = fopen($outputPath, 'wb');
        if (!$fp) {
            throw new RuntimeException("Could not open file for writing: {$outputPath}");
        }

        try {
            // RIFF chunk descriptor
            fwrite($fp, 'RIFF');
            fwrite($fp, pack('V', $riffSize));
            fwrite($fp, 'WAVE');

            // 'fmt ' sub-chunk
            fwrite($fp, 'fmt ');
            fwrite($fp, pack('V', 16));             // Subchunk1Size for PCM
            fwrite($fp, pack('v', 1));              // AudioFormat (1 = PCM)
            fwrite($fp, pack('v', $numChannels));
            fwrite($fp, pack('V', $sampleRate));
            fwrite($fp, pack('V', $byteRate));
            fwrite($fp, pack('v', $blockAlign));
            fwrite($fp, pack('v', $bitsPerSample));

            // 'data' sub-chunk
            fwrite($fp, 'data');
            fwrite($fp, pack('V', $dataSize));

            // Pack samples
            $binary = '';
            foreach ($samples as $s) {
                // Clamping
                $clamped = max(-1.0, min(1.0, $s));
                $intVal = (int)round($clamped * 32767);
                $binary .= pack('s', $intVal);
                if (strlen($binary) >= 65536) {
                    fwrite($fp, $binary);
                    $binary = '';
                }
            }
            if ($binary !== '') {
                fwrite($fp, $binary);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Slice an existing WAV file from startSec to endSec into a new WAV file.
     */
    public static function sliceWav(string $sourcePath, string $targetPath, float $startSec, float $endSec): void
    {
        $header = self::readHeader($sourcePath);
        $sampleRate = $header['sampleRate'];
        $channels = $header['channels'];
        $blockAlign = $header['blockAlign'];

        $startByte = $header['dataOffset'] + (int)floor($startSec * $sampleRate) * $blockAlign;
        $endByte = $header['dataOffset'] + (int)ceil($endSec * $sampleRate) * $blockAlign;
        $maxByte = $header['dataOffset'] + $header['dataSize'];

        $startByte = max($header['dataOffset'], min($startByte, $maxByte));
        $endByte = max($startByte, min($endByte, $maxByte));
        $sliceSize = $endByte - $startByte;

        $fpSrc = fopen($sourcePath, 'rb');
        $fpDst = fopen($targetPath, 'wb');

        if (!$fpSrc || !$fpDst) {
            if ($fpSrc) fclose($fpSrc);
            if ($fpDst) fclose($fpDst);
            throw new RuntimeException("Failed to open file for slicing: {$sourcePath} -> {$targetPath}");
        }

        try {
            $riffSize = 36 + $sliceSize;
            fwrite($fpDst, 'RIFF');
            fwrite($fpDst, pack('V', $riffSize));
            fwrite($fpDst, 'WAVE');

            fwrite($fpDst, 'fmt ');
            fwrite($fpDst, pack('V', 16));
            fwrite($fpDst, pack('v', 1));
            fwrite($fpDst, pack('v', $channels));
            fwrite($fpDst, pack('V', $sampleRate));
            fwrite($fpDst, pack('V', $header['byteRate']));
            fwrite($fpDst, pack('v', $blockAlign));
            fwrite($fpDst, pack('v', $header['bitsPerSample']));

            fwrite($fpDst, 'data');
            fwrite($fpDst, pack('V', $sliceSize));

            fseek($fpSrc, $startByte);
            $bytesRemaining = $sliceSize;
            $chunkSize = 65536;

            while ($bytesRemaining > 0 && !feof($fpSrc)) {
                $readLength = min($chunkSize, $bytesRemaining);
                $buf = fread($fpSrc, $readLength);
                if ($buf === false || strlen($buf) === 0) {
                    break;
                }
                fwrite($fpDst, $buf);
                $bytesRemaining -= strlen($buf);
            }
        } finally {
            fclose($fpSrc);
            fclose($fpDst);
        }
    }
}
