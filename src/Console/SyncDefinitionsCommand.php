<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Console;

use Cbox\Billing\Client\Contracts\DefinitionTransport;
use Cbox\Billing\Client\Definitions\DefinitionManifest;
use Cbox\Billing\Client\Exceptions\TransportException;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

final class SyncDefinitionsCommand extends Command
{
    protected $signature = 'billing:sync-definitions {file? : Definition JSON file; defaults to billing-definitions.json} {--validate-only : Validate the local file without publishing}';

    protected $description = 'Synchronize feature and meter definitions without assigning entitlements or changing prices.';

    public function handle(DefinitionTransport $transport): int
    {
        $file = $this->argument('file');
        $path = is_string($file) && $file !== '' ? $file : base_path('billing-definitions.json');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
            $this->error('A readable definition manifest of at most 1 MiB is required.');

            return self::FAILURE;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            $this->error('The definition manifest could not be read.');

            return self::FAILURE;
        }
        try {
            $manifest = DefinitionManifest::fromJson($json);
            if ($this->option('validate-only')) {
                $this->info('Definition manifest is valid: '.$manifest->version.'.');

                return self::SUCCESS;
            }
            $transport->sync($manifest);
        } catch (InvalidArgumentException|JsonException|TransportException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Billing acknowledged all definitions for '.$manifest->version.'.');

        return self::SUCCESS;
    }
}
