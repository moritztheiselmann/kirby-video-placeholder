<?php

namespace Cookbook\VideoPlaceholder\Tests;

use Cookbook\VideoPlaceholder\Placeholder;
use kornrunner\Blurhash\Blurhash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the extraction pipeline against a video generated on the fly, so
 * the suite needs no fixture binaries and no Kirby instance.
 */
#[CoversClass(Placeholder::class)]
final class PlaceholderTest extends TestCase
{
    private static string $dir;
    private static string $clip;

    public static function setUpBeforeClass(): void
    {
        self::$dir  = sys_get_temp_dir() . '/vp-tests-' . bin2hex(random_bytes(4));
        self::$clip = self::$dir . '/clip.mp4';

        mkdir(self::$dir, 0777, true);

        if (Placeholder::isAvailable() === false) {
            return;
        }

        // One second of red followed by one second of blue: the colour tells
        // us which frame ffmpeg actually handed back.
        exec(sprintf(
            'ffmpeg -v error -f lavfi -i color=c=red:s=64x64:r=10:d=1 '
            . '-f lavfi -i color=c=blue:s=64x64:r=10:d=1 '
            . '-filter_complex "[0:v][1:v]concat=n=2:v=1" -pix_fmt yuv420p -y %s 2>&1',
            escapeshellarg(self::$clip)
        ));
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$dir . '/*') ?: []);
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        if (Placeholder::isAvailable() === false) {
            $this->markTestSkipped('ffmpeg/ffprobe not found on PATH');
        }
    }

    public function testProbeReturnsDimensions(): void
    {
        $this->assertSame([64, 64], Placeholder::probe(self::$clip));
    }

    public function testProbeFailsSoftOnMissingFile(): void
    {
        $this->assertSame([null, null], Placeholder::probe('/does/not/exist.mp4'));
    }

    public function testFirstFrameIsEncoded(): void
    {
        $hash = Placeholder::fromVideo(self::$clip, 0.0);

        $this->assertIsString($hash);
        $this->assertGreaterThan(6, strlen($hash));

        [$r, $g, $b] = $this->averageColor($hash);

        $this->assertGreaterThan(200, $r, 'first frame should decode to red');
        $this->assertLessThan(60, $g);
        $this->assertLessThan(60, $b);
    }

    public function testSeekSelectsALaterFrame(): void
    {
        [$r, $g, $b] = $this->averageColor(Placeholder::fromVideo(self::$clip, 1.5));

        $this->assertGreaterThan(200, $b, 'seeking past 1s should decode to blue');
        $this->assertLessThan(60, $r);
        $this->assertLessThan(60, $g);
    }

    public function testMissingSourceReturnsNull(): void
    {
        $this->assertNull(Placeholder::fromVideo('/does/not/exist.mp4'));
    }

    public function testDataUriIsAnInlinePng(): void
    {
        $hash = Placeholder::fromVideo(self::$clip, 0.0);
        $uri  = Placeholder::toDataUri($hash, 32, 18);

        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        $binary = base64_decode(substr($uri, strlen('data:image/png;base64,')));
        $size   = getimagesizefromstring($binary);

        $this->assertSame([32, 18], [$size[0], $size[1]]);
        $this->assertLessThan(2048, strlen($uri), 'placeholder should stay tiny');
    }

    public function testInvalidHashReturnsNull(): void
    {
        $this->assertNull(Placeholder::toDataUri('!!!not-a-hash!!!', 8, 8));
    }

    /**
     * Mean RGB of a decoded hash.
     */
    private function averageColor(string $hash): array
    {
        $pixels = Blurhash::decode($hash, 4, 4);
        $sum    = [0, 0, 0];
        $count  = 0;

        foreach ($pixels as $row) {
            foreach ($row as [$r, $g, $b]) {
                $sum[0] += $r;
                $sum[1] += $g;
                $sum[2] += $b;
                $count++;
            }
        }

        return array_map(fn ($channel) => (int)round($channel / $count), $sum);
    }
}
