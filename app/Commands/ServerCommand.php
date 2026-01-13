<?php

namespace App\Commands;

use App\Models\Server;
use App\Services\ConnectionTester;
use App\Services\ServerManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'server
                            {action? : The action to perform (list, add, edit, delete, test, default)}
                            {name? : The server name for edit, delete, test, or default actions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage MySQL server configurations';

    /**
     * Execute the console command.
     */
    public function handle(ServerManager $manager, ConnectionTester $tester): int
    {
        $action = $this->argument('action');
        $name = $this->argument('name');

        if (! $action) {
            return $this->showMenu($manager, $tester);
        }

        return match ($action) {
            'list' => $this->listServers($manager),
            'add' => $this->addServer($manager),
            'edit' => $this->editServer($manager, $name),
            'delete' => $this->deleteServer($manager, $name),
            'test' => $this->testServer($manager, $tester, $name),
            'default' => $this->setDefault($manager, $name),
            default => $this->error("Unknown action: {$action}") ?? self::FAILURE,
        };
    }

    /**
     * Show interactive menu.
     */
    protected function showMenu(ServerManager $manager, ConnectionTester $tester): int
    {
        $choice = select(
            label: 'Server Management',
            options: [
                'list' => 'List servers',
                'add' => 'Add server',
                'edit' => 'Edit server',
                'delete' => 'Delete server',
                'test' => 'Test connection',
                'default' => 'Set default server',
                'exit' => 'Exit',
            ]
        );

        return match ($choice) {
            'list' => $this->listServers($manager),
            'add' => $this->addServer($manager),
            'edit' => $this->editServer($manager),
            'delete' => $this->deleteServer($manager),
            'test' => $this->testServer($manager, $tester),
            'default' => $this->setDefault($manager),
            'exit' => self::SUCCESS,
            default => self::SUCCESS,
        };
    }

    /**
     * List all servers.
     */
    protected function listServers(ServerManager $manager): int
    {
        $servers = $manager->all();

        if ($servers->isEmpty()) {
            $this->info('No servers configured.');

            return self::SUCCESS;
        }

        $rows = $servers->map(function (Server $server) {
            return [
                $server->id,
                $server->name,
                $server->host,
                $server->port,
                $server->username,
                $server->database ?? '-',
                $server->is_default ? '✓' : '',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Name', 'Host', 'Port', 'Username', 'Database', 'Default'],
            $rows
        );

        return self::SUCCESS;
    }

    /**
     * Add a new server.
     */
    protected function addServer(ServerManager $manager): int
    {
        $this->info('Add New Server');

        $data = [
            'name' => text(
                label: 'Server name',
                required: true,
                validate: fn ($value) => match (true) {
                    empty($value) => 'Server name is required',
                    $manager->nameExists($value) => 'A server with this name already exists',
                    default => null,
                }
            ),
            'host' => text(
                label: 'Host',
                default: 'localhost',
                required: true,
                validate: fn ($value) => empty($value) ? 'Host is required' : null
            ),
            'port' => text(
                label: 'Port',
                default: '3306',
                required: true,
                validate: fn ($value) => match (true) {
                    empty($value) => 'Port is required',
                    ! is_numeric($value) => 'Port must be a number',
                    $value < 1 || $value > 65535 => 'Port must be between 1 and 65535',
                    default => null,
                }
            ),
            'username' => text(
                label: 'Username',
                default: 'root',
                required: true,
                validate: fn ($value) => empty($value) ? 'Username is required' : null
            ),
            'password' => password(
                label: 'Password (optional)',
                required: false
            ) ?: null,
            'database' => text(label: 'Database (optional)') ?: null,
            'charset' => text(
                label: 'Charset',
                default: 'utf8mb4',
                required: true,
                validate: fn ($value) => empty($value) ? 'Charset is required' : null
            ),
            'collation' => text(
                label: 'Collation',
                default: 'utf8mb4_unicode_ci',
                required: true,
                validate: fn ($value) => empty($value) ? 'Collation is required' : null
            ),
        ];

        // Ask about SSH tunnel
        if (confirm(label: 'Use SSH tunnel?', default: false)) {
            $data['ssh_host'] = text(
                label: 'SSH Host',
                required: true,
                validate: fn ($value) => empty($value) ? 'SSH host is required when using SSH tunnel' : null
            );
            $data['ssh_port'] = text(
                label: 'SSH Port',
                default: '22',
                required: true,
                validate: fn ($value) => match (true) {
                    empty($value) => 'SSH port is required',
                    ! is_numeric($value) => 'SSH port must be a number',
                    $value < 1 || $value > 65535 => 'SSH port must be between 1 and 65535',
                    default => null,
                }
            );
            $data['ssh_username'] = text(
                label: 'SSH Username',
                required: true,
                validate: fn ($value) => empty($value) ? 'SSH username is required when using SSH tunnel' : null
            );

            $authMethod = select(
                label: 'SSH Authentication',
                options: ['password' => 'Password', 'key' => 'Key']
            );

            if ($authMethod === 'password') {
                $data['ssh_password'] = password(
                    label: 'SSH Password',
                    required: true,
                    validate: fn ($value) => empty($value) ? 'SSH password is required for password authentication' : null
                );
            } else {
                $data['ssh_key_path'] = text(
                    label: 'SSH Key Path',
                    default: '~/.ssh/id_rsa',
                    required: true,
                    validate: fn ($value) => empty($value) ? 'SSH key path is required for key authentication' : null
                );
            }
        }

        // Create server
        $server = $manager->create($data);

        $this->info("Server '{$server->name}' created successfully!");

        // Ask to set as default
        if ($manager->count() === 1 || confirm(label: 'Set as default server?', default: false)) {
            $manager->setDefault($server);
            $this->info('Set as default server.');
        }

        return self::SUCCESS;
    }

    /**
     * Edit a server.
     */
    protected function editServer(ServerManager $manager, ?string $name = null): int
    {
        if (! $name) {
            $names = $manager->getNames()->toArray();

            if (empty($names)) {
                $this->error('No servers configured.');

                return self::FAILURE;
            }

            $name = select(
                label: 'Select server to edit',
                options: array_combine($names, $names)
            );
        }

        $server = $manager->findByName($name);

        if (! $server) {
            $this->error("Server '{$name}' not found.");

            return self::FAILURE;
        }

        $this->info("Edit Server: {$server->name}");

        $data = [
            'name' => text(
                label: 'Server name',
                default: $server->name,
                required: true,
                validate: fn ($value) => match (true) {
                    empty($value) => 'Server name is required',
                    $value !== $server->name && $manager->nameExists($value) => 'A server with this name already exists',
                    default => null,
                }
            ),
            'host' => text(
                label: 'Host',
                default: $server->host,
                required: true,
                validate: fn ($value) => empty($value) ? 'Host is required' : null
            ),
            'port' => text(
                label: 'Port',
                default: (string) $server->port,
                required: true,
                validate: fn ($value) => match (true) {
                    empty($value) => 'Port is required',
                    ! is_numeric($value) => 'Port must be a number',
                    $value < 1 || $value > 65535 => 'Port must be between 1 and 65535',
                    default => null,
                }
            ),
            'username' => text(
                label: 'Username',
                default: $server->username,
                required: true,
                validate: fn ($value) => empty($value) ? 'Username is required' : null
            ),
            'database' => text(label: 'Database (optional)', default: $server->database ?? '') ?: null,
            'charset' => text(
                label: 'Charset',
                default: $server->charset,
                required: true,
                validate: fn ($value) => empty($value) ? 'Charset is required' : null
            ),
            'collation' => text(
                label: 'Collation',
                default: $server->collation,
                required: true,
                validate: fn ($value) => empty($value) ? 'Collation is required' : null
            ),
        ];

        $updatePassword = confirm(label: 'Update password?', default: false);
        if ($updatePassword) {
            $data['password'] = password(
                label: 'Password (optional)',
                required: false
            ) ?: null;
        }

        $manager->update($server, $data);

        $this->info("Server '{$server->name}' updated successfully!");

        return self::SUCCESS;
    }

    /**
     * Delete a server.
     */
    protected function deleteServer(ServerManager $manager, ?string $name = null): int
    {
        if (! $name) {
            $names = $manager->getNames()->toArray();

            if (empty($names)) {
                $this->error('No servers configured.');

                return self::FAILURE;
            }

            $name = select(
                label: 'Select server to delete',
                options: array_combine($names, $names)
            );
        }

        $server = $manager->findByName($name);

        if (! $server) {
            $this->error("Server '{$name}' not found.");

            return self::FAILURE;
        }

        if (! confirm(label: "Are you sure you want to delete '{$server->name}'?", default: false)) {
            $this->info('Deletion cancelled.');

            return self::SUCCESS;
        }

        $manager->delete($server);

        $this->info("Server '{$server->name}' deleted successfully!");

        return self::SUCCESS;
    }

    /**
     * Test server connection.
     */
    protected function testServer(ServerManager $manager, ConnectionTester $tester, ?string $name = null): int
    {
        if (! $name) {
            $names = $manager->getNames()->toArray();

            if (empty($names)) {
                $this->error('No servers configured.');

                return self::FAILURE;
            }

            $name = select(
                label: 'Select server to test',
                options: array_combine($names, $names)
            );
        }

        $server = $manager->findByName($name);

        if (! $server) {
            $this->error("Server '{$name}' not found.");

            return self::FAILURE;
        }

        $this->info("Testing connection to '{$server->name}'...");

        $result = $tester->test($server);

        if ($result['success']) {
            $this->info('✓ '.$result['message']);

            if (isset($result['details'])) {
                $this->line('  Duration: '.$result['details']['duration_ms'].'ms');
                $this->line('  Server Version: '.$result['details']['server_version']);
            }
        } else {
            $this->error('✗ '.$result['message']);
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Set default server.
     */
    protected function setDefault(ServerManager $manager, ?string $name = null): int
    {
        if (! $name) {
            $names = $manager->getNames()->toArray();

            if (empty($names)) {
                $this->error('No servers configured.');

                return self::FAILURE;
            }

            $name = select(
                label: 'Select default server',
                options: array_combine($names, $names)
            );
        }

        $server = $manager->findByName($name);

        if (! $server) {
            $this->error("Server '{$name}' not found.");

            return self::FAILURE;
        }

        $manager->setDefault($server);

        $this->info("'{$server->name}' is now the default server.");

        return self::SUCCESS;
    }
}
