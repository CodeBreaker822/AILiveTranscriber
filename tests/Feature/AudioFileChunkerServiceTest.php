<?php

namespace Tests\Feature;

use App\Services\AudioFileChunkerService;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AudioFileChunkerServiceTest extends TestCase
{
    public function test_it_extracts_only_the_requested_audio_segment(): void
    {
        $ffmpegPath = base_path('ffmpeg/bin/ffmpeg.exe');

        if (! is_file($ffmpegPath)) {
            $this->markTestSkipped('Bundled ffmpeg binary is not available.');
        }

        $samplePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aitranscriber-chunker-test-'.uniqid().'.wav';
        $process = new Process([
            $ffmpegPath,
            '-y',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:duration=125',
            '-ac',
            '1',
            '-ar',
            '16000',
            $samplePath,
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $sourceSize = filesize($samplePath);
        $chunker = app(AudioFileChunkerService::class);
        $file = new UploadedFile($samplePath, 'aitranscriber-chunker-test.wav', 'audio/wav', null, true);
        $session = $chunker->createSession($file);
        $sections = $chunker->buildSections($session['duration_ms'], 60);
        $segment = $chunker->extractSegment(
            $session['session_id'],
            $sections[0]['index'],
            $sections[0]['start_ms'],
            $sections[0]['duration_ms'],
        );

        $this->assertSame(3, count($sections));
        $this->assertGreaterThanOrEqual(59000, $segment['duration_ms']);
        $this->assertLessThanOrEqual(61000, $segment['duration_ms']);
        $this->assertLessThan($sourceSize, $segment['size']);
    }
}
