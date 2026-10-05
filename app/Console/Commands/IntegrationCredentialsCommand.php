<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Integrations\IntegrationCredentialTransfer;
use App\Support\Integrations\IntegrationCredentialTransferException;
use Illuminate\Console\Command;
use JsonException;

class IntegrationCredentialsCommand extends Command
{
    protected $signature = 'integrations:credentials
        {action : export or import}
        {path? : JSON file. Defaults to storage/app/private/integration-credentials.json}';

    protected $description = 'Export or import connected-app API keys and configuration';

    public function handle(IntegrationCredentialTransfer $transfer): int
    {
        $action = $this->argument('action');
        if ($action !== 'export' && $action !== 'import') {
            $this->error('Action must be export or import.');

            return self::FAILURE;
        }

        $path = $this->argument('path');
        $path = is_string($path) && $path !== '' ? $path : IntegrationCredentialTransfer::defaultPath();

        if ($action === 'export') {
            return $this->export($transfer, $path);
        }

        return $this->import($transfer, $path);
    }

    private function export(IntegrationCredentialTransfer $transfer, string $path): int
    {
        if (! $this->ensureDirectory($path)) {
            return self::FAILURE;
        }

        $result = $transfer->export();

        try {
            $json = json_encode(
                $result['document'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            $this->error('Could not encode integration credentials.');

            return self::FAILURE;
        }

        if (file_put_contents($path, $json."\n") === false) {
            $this->error('Could not write '.$path);

            return self::FAILURE;
        }

        chmod($path, 0600);

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->info('Exported '.IntegrationCredentialTransfer::formatCounts($result['counts']).'.');
        $this->info('Wrote '.$path);

        return self::SUCCESS;
    }

    private function import(IntegrationCredentialTransfer $transfer, string $path): int
    {
        if (! is_file($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }

        try {
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('File is not valid JSON: '.$path);

            return self::FAILURE;
        }

        if (! is_array($document)) {
            $this->error('File is not valid JSON: '.$path);

            return self::FAILURE;
        }

        try {
            $counts = $transfer->import($document);
        } catch (IntegrationCredentialTransferException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Imported '.IntegrationCredentialTransfer::formatCounts($counts).'.');

        return self::SUCCESS;
    }

    private function ensureDirectory(string $path): bool
    {
        $directory = dirname($path);
        if (is_dir($directory)) {
            return true;
        }

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->error('Directory missing: '.$directory);

            return false;
        }

        return true;
    }
}
