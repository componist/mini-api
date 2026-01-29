<?php

namespace Componist\MiniApi\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateMiniApiKeyCommand extends Command
{
    protected $signature = 'mini-api:generate-key
                            {--show : Nur Key anzeigen, nicht in .env schreiben}
                            {--force : Vorhandenen Key in .env überschreiben}
                            {--length=64 : Länge des Keys}';

    protected $description = 'Generiert einen API-Key für Mini API und schreibt ihn in .env (oder nur anzeigen mit --show)';

    public function handle(): int
    {
        $length = (int) $this->option('length');
        $key = Str::random(max(32, min(128, $length)));

        if ($this->option('show')) {
            $this->line($key);
            return self::SUCCESS;
        }

        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->error('.env nicht gefunden.');
            return self::FAILURE;
        }

        $content = file_get_contents($envPath);
        $hasKey = preg_match('/^\s*MINI_API_KEY\s*=/m', $content);

        if ($hasKey && ! $this->option('force')) {
            $this->warn('MINI_API_KEY existiert bereits. Nutze --force zum Überschreiben.');
            return self::FAILURE;
        }

        if ($hasKey) {
            $content = preg_replace('/^\s*MINI_API_KEY\s*=.*$/m', 'MINI_API_KEY=' . $key, $content);
        } else {
            $content = rtrim($content) . "\n\nMINI_API_KEY=" . $key . "\n";
            if (! str_contains($content, 'MINI_API_AUTH_ENABLED')) {
                $content .= "MINI_API_AUTH_ENABLED=true\n";
            }
        }

        if (! file_put_contents($envPath, $content)) {
            $this->error('.env konnte nicht geschrieben werden.');
            return self::FAILURE;
        }

        $this->info('API-Key wurde in .env gesetzt.');
        $this->line('Key: ' . $key);
        return self::SUCCESS;
    }
}
