<?php

namespace Tests\Unit;

use App\Services\PanoramaCompressor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PanoramaCompressorTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * A busy (gradient + random shapes) JPEG, so it behaves like a real photo
     * instead of compressing to nothing.
     */
    public static function noisyJpeg(int $width, int $height, int $quality = 97): string
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(7);

        for ($y = 0; $y < $height; $y += 2) {
            $c = imagecolorallocate($image, ($y * 255 / $height), 120 + ($y % 90), 255 - ($y * 255 / $height));
            imageline($image, 0, $y, $width, $y, $c);
            imageline($image, 0, $y + 1, $width, $y + 1, $c);
        }
        for ($i = 0; $i < 1500; $i++) {
            $c = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledellipse($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(5, 300), mt_rand(5, 300), $c);
        }

        $path = tempnam(sys_get_temp_dir(), 'pano').'.jpg';
        imagejpeg($image, $path, $quality);

        return $path;
    }

    private function upload(string $path, string $name = 'pano.jpg'): UploadedFile
    {
        $this->temp[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @param  array{contents: string, extension: string}  $result
     * @return array{0: int, 1: int}
     */
    private function dimensions(array $result): array
    {
        $info = getimagesizefromstring($result['contents']);

        return [$info[0], $info[1]];
    }

    public function test_wide_photo_is_scaled_to_8192_and_saved_as_webp(): void
    {
        $file = $this->upload(self::noisyJpeg(10000, 5000));

        $result = (new PanoramaCompressor)->compress($file);

        $this->assertNotNull($result);
        $this->assertSame('webp', $result['extension']);
        $this->assertSame([8192, 4096], $this->dimensions($result));
        $this->assertLessThan(filesize($file->getRealPath()), strlen($result['contents']));
    }

    public function test_photo_within_8192_keeps_its_size_and_is_never_upscaled(): void
    {
        $file = $this->upload(self::noisyJpeg(3000, 1500));

        $result = (new PanoramaCompressor)->compress($file);

        $this->assertNotNull($result);
        $this->assertSame([3000, 1500], $this->dimensions($result));
    }

    public function test_original_is_kept_when_the_result_would_not_be_smaller(): void
    {
        // A tiny, already very small JPEG: re-encoding can't beat it.
        $path = tempnam(sys_get_temp_dir(), 'pano').'.jpg';
        $image = imagecreatetruecolor(40, 20);
        imagejpeg($image, $path, 1);

        $file = $this->upload($path);
        $result = (new PanoramaCompressor)->compress($file);

        $this->assertTrue($result === null || strlen($result['contents']) < filesize($path));
    }

    public function test_unreadable_file_falls_back_to_the_original(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pano').'.jpg';
        file_put_contents($path, 'this is not an image');

        $this->assertNull((new PanoramaCompressor)->compress($this->upload($path)));
    }

    public function test_memory_limit_is_restored_after_compressing(): void
    {
        $before = ini_get('memory_limit');

        (new PanoramaCompressor)->compress($this->upload(self::noisyJpeg(9000, 4500)));

        $this->assertSame($before, ini_get('memory_limit'));
    }
}
