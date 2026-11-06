<?php

namespace Tests\Feature;

use App\Support\CorsOrigins;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\TestCase;

class StorageCorsTest extends TestCase
{
    public function test_config_uses_the_shared_allowlist_without_a_wildcard(): void
    {
        $this->assertSame(CorsOrigins::allowed(), config('cors.allowed_origins'));
        $this->assertSame(CorsOrigins::patterns(), config('cors.allowed_origins_patterns'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertTrue(config('cors.supports_credentials'));
    }

    public function test_known_frontends_and_local_quasar_are_allowed(): void
    {
        foreach ([
            'https://greyon.site',
            'https://www.greyon.site',
            'https://dev-greyon.mxlab.site',
            'http://localhost:9400',
            'http://localhost:5555',
            'http://127.0.0.1:5555',
        ] as $origin) {
            $this->assertTrue(CorsOrigins::allows($origin), $origin);

            $response = $this->withHeaders([
                'Origin' => $origin,
                'Accept' => 'application/json',
            ])->get('/');

            $response->assertOk();
            $response->assertHeader('Access-Control-Allow-Origin', $origin);
            $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    public function test_unlisted_origins_are_not_reflected(): void
    {
        foreach ([
            'https://evil.example',
            'https://greyon.site.evil.com',
            'http://localhost.evil.com',
            'https://localhost:9400',
        ] as $origin) {
            $this->assertFalse(CorsOrigins::allows($origin), $origin);

            $response = $this->withHeaders([
                'Origin' => $origin,
                'Accept' => 'application/json',
            ])->get('/');

            $this->assertNotSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
            $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        }
    }

    public function test_normalize_trims_and_drops_wildcards_and_injection(): void
    {
        $this->assertSame(
            ['https://ok.example', 'http://localhost:9400'],
            CorsOrigins::normalize([
                '  https://ok.example/  ',
                '*',
                "https://evil.example\nadd_header",
                'https://ok.example',
                'http://localhost:9400',
                '',
            ]),
        );
    }

    public function test_nginx_map_mirrors_config_and_rejects_unsafe_patterns(): void
    {
        $exit = Artisan::call('storage:cors-map');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('*', $output);
        $this->assertStringContainsString('map $http_origin $storage_cors_origin {', $output);
        $this->assertStringContainsString('default "";', $output);
        $this->assertStringContainsString('map $storage_cors_origin $storage_cors_credentials {', $output);
        $this->assertStringContainsString('default "true";', $output);

        foreach (config('cors.allowed_origins') as $origin) {
            $this->assertStringContainsString('"'.$origin.'" $http_origin;', $output);
        }
        $this->assertStringContainsString('~^http://localhost:\d+$ $http_origin;', $output);
        $this->assertStringContainsString('~^http://127\.0\.0\.1:\d+$ $http_origin;', $output);

        $safe = CorsOrigins::nginxMap(
            ['https://greyon.site', '*', "https://evil.example\nadd_header", 'https://ok.example/'],
            ['#^http://localhost:\d+$#'],
        );
        $this->assertStringContainsString('"https://greyon.site" $http_origin;', $safe);
        $this->assertStringContainsString('"https://ok.example" $http_origin;', $safe);
        $this->assertStringNotContainsString('add_header', $safe);
        $this->assertStringNotContainsString('*', $safe);

        $this->expectException(InvalidArgumentException::class);
        CorsOrigins::nginxMap([], ['#has space#']);
    }

    public function test_nginx_template_applies_the_map_to_panorama_files(): void
    {
        $toml = file_get_contents(base_path('nixpacks.toml'));
        $this->assertIsString($toml);

        $mapAt = strpos($toml, 'storage:cors-map --path=/etc/nginx-storage-cors.conf');
        $nginxAt = strpos($toml, 'prestart.mjs');
        $this->assertNotFalse($mapAt);
        $this->assertNotFalse($nginxAt);
        $this->assertLessThan($nginxAt, $mapAt);

        $this->assertStringContainsString('include /etc/nginx-storage-cors.conf;', $toml);
        $this->assertStringContainsString('location ^~ /storage/uploads/rooms/', $toml);
        $this->assertStringContainsString('Access-Control-Allow-Origin $storage_cors_origin', $toml);
        $this->assertStringContainsString('Access-Control-Allow-Credentials $storage_cors_credentials', $toml);
        $this->assertStringContainsString('Vary "Origin"', $toml);
        $this->assertStringContainsString('Cache-Control "private, max-age=14400"', $toml);
        $this->assertStringNotContainsString('Access-Control-Allow-Origin "*"', $toml);
        $this->assertStringNotContainsString("Access-Control-Allow-Origin '*'", $toml);
    }
}
