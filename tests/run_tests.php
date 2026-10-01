<?php

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/VoiceDatasetTest.php';

use EidCloud\VoiceDataset\Tests\VoiceDatasetTest;

echo "========================================================\n";
echo "🎙️  EidCloud Voice Dataset Test Runner (PHP " . PHP_VERSION . ")\n";
echo "========================================================\n\n";

$suite = new VoiceDatasetTest();
$tests = [
    'WAV Processor Read/Write/Slice' => 'testWavProcessorReadWrite',
    'Voice Activity & Silence Trimming' => 'testSilenceTrimming',
    'EBU R128 / RMS Loudness Normalization' => 'testLoudnessNormalization',
    'Smart Acoustic Segmentation (2s-12s)' => 'testSmartSegmentation',
    'Quality Gate (SNR, Clipping, 50Hz Hum)' => 'testQualityGateClippedAndHum',
    'LJSpeech Exporter (metadata & train/val split)' => 'testLjSpeechExporterAndSplits',
    'End-to-End Factory Build & Strict Audit' => 'testEndToEndDatasetFactoryBuildAndAudit',
];

$passed = 0;
$failed = 0;
$total = count($tests);

foreach ($tests as $label => $method) {
    echo "  RUN  {$label}... ";
    $startTime = microtime(true);
    try {
        $suite->$method();
        $durationMs = round((microtime(true) - $startTime) * 1000, 1);
        echo "[\033[32mPASSED\033[0m] ({$durationMs}ms)\n";
        $passed++;
    } catch (\Throwable $e) {
        $durationMs = round((microtime(true) - $startTime) * 1000, 1);
        echo "[\033[31mFAILED\033[0m] ({$durationMs}ms)\n";
        echo "       \033[31mError:\033[0m " . $e->getMessage() . "\n";
        if (getenv('VERBOSE')) {
            echo "       " . str_replace("\n", "\n       ", $e->getTraceAsString()) . "\n";
        }
        $failed++;
    }
}

echo "\n--------------------------------------------------------\n";
echo "TEST RESULTS: {$passed}/{$total} PASSED (" . round(($passed / $total) * 100, 1) . "%)\n";
echo "--------------------------------------------------------\n";

if ($failed > 0) {
    echo "❌ TEST SUITE FAILED.\n";
    exit(1);
}

echo "✨ ALL TESTS PASSED SUCCESSFULLY (100% PASS)!\n";
exit(0);
