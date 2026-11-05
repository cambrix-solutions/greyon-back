<?php

namespace App\Console\Commands;

use App\Support\CorsOrigins;
use Illuminate\Console\Command;

/**
 * Public-disk files are served by nginx (try_files) and never reach
 * Laravel's CORS middleware. Write the allowlist from config/cors.php
 * so /storage/uploads/rooms can be read cross-origin.
 */
class RenderStorageCorsMap extends Command
{
    protected $signature = 'storage:cors-map {--path= : Write the nginx map to this file instead of stdout}';

    protected $description = 'Render the nginx CORS map for public storage files from config/cors.php';

    public function handle(): int
    {
        $origins = config('cors.allowed_origins', []);
        $patterns = config('cors.allowed_origins_patterns', []);
        if (! is_array($origins) || ! is_array($patterns)) {
            $this->error('config/cors.php allowed_origins and allowed_origins_patterns must be lists.');

            return self::FAILURE;
        }

        $map = CorsOrigins::nginxMap($origins, $patterns);
        $path = $this->option('path');
        if (is_string($path) && $path !== '') {
            $dir = dirname($path);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                $this->error("Cannot create {$dir}");

                return self::FAILURE;
            }
            if (file_put_contents($path, $map) === false) {
                $this->error("Cannot write {$path}");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->output->write($map);

        return self::SUCCESS;
    }
}
