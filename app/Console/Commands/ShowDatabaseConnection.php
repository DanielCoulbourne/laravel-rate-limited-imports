<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShowDatabaseConnection extends Command
{
    protected $signature = 'db:connection';

    protected $description = 'Display TablePlus-compatible database connection URL';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        // Build TablePlus connection URL
        $tablePlusUrl = $this->buildTablePlusUrl($config);

        $this->newLine();
        $this->info('📋 TablePlus Connection URL (click or copy to open):');
        $this->newLine();
        $this->line($tablePlusUrl);
        $this->newLine();

        // Test the connection
        try {
            DB::connection()->getPdo();
            $this->info('✓ Database connection successful');
        } catch (\Exception $e) {
            $this->error('✗ Connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Build a TablePlus-compatible connection URL
     */
    private function buildTablePlusUrl(array $config): string
    {
        $driver = $config['driver'];

        if ($driver === 'sqlite') {
            $database = $config['database'];
            return "sqlite://{$database}";
        }

        // For MySQL, PostgreSQL, etc.
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? $this->getDefaultPort($driver);
        $database = $config['database'] ?? '';
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';

        // Map Laravel driver names to TablePlus protocol names
        $protocol = match($driver) {
            'mysql' => 'mysql',
            'pgsql' => 'postgresql',
            'sqlsrv' => 'sqlserver',
            default => $driver,
        };

        // Build URL: protocol://username:password@host:port/database
        $url = "{$protocol}://";

        if ($username) {
            $url .= urlencode($username);
            if ($password) {
                $url .= ':' . urlencode($password);
            }
            $url .= '@';
        }

        $url .= "{$host}:{$port}";

        if ($database) {
            $url .= '/' . urlencode($database);
        }

        return $url;
    }

    /**
     * Get default port for database driver
     */
    private function getDefaultPort(string $driver): int
    {
        return match($driver) {
            'mysql' => 3306,
            'pgsql' => 5432,
            'sqlsrv' => 1433,
            default => 3306,
        };
    }
}
