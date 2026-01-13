<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Collection;

class ServerManager
{
    /**
     * Get all servers.
     */
    public function all(): Collection
    {
        return Server::all();
    }

    /**
     * Get server by name.
     */
    public function findByName(string $name): ?Server
    {
        return Server::where('name', $name)->first();
    }

    /**
     * Get server by ID.
     */
    public function findById(int $id): ?Server
    {
        return Server::find($id);
    }

    /**
     * Get the default server.
     */
    public function getDefault(): ?Server
    {
        return Server::default()->first();
    }

    /**
     * Create a new server.
     */
    public function create(array $data): Server
    {
        return Server::create($data);
    }

    /**
     * Update a server.
     */
    public function update(Server $server, array $data): bool
    {
        return $server->update($data);
    }

    /**
     * Delete a server.
     */
    public function delete(Server $server): bool
    {
        return $server->delete();
    }

    /**
     * Set a server as the default.
     */
    public function setDefault(Server $server): bool
    {
        return $server->setAsDefault();
    }

    /**
     * Check if a server name exists.
     */
    public function exists(string $name): bool
    {
        return Server::where('name', $name)->exists();
    }

    /**
     * Get server count.
     */
    public function count(): int
    {
        return Server::count();
    }

    /**
     * Get servers as a list of names.
     */
    public function getNames(): Collection
    {
        return Server::pluck('name');
    }

    /**
     * Get servers as name => id pairs.
     */
    public function getNameIdPairs(): Collection
    {
        return Server::pluck('id', 'name');
    }

    /**
     * Check if a server name already exists.
     */
    public function nameExists(string $name): bool
    {
        return Server::where('name', $name)->exists();
    }

    /**
     * Validate server data.
     */
    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Server name is required';
        } elseif (strlen($data['name']) > 255) {
            $errors['name'] = 'Server name must not exceed 255 characters';
        }

        if (empty($data['host'])) {
            $errors['host'] = 'Host is required';
        }

        if (isset($data['port'])) {
            if (! is_numeric($data['port'])) {
                $errors['port'] = 'Port must be a number';
            } elseif ($data['port'] < 1 || $data['port'] > 65535) {
                $errors['port'] = 'Port must be between 1 and 65535';
            }
        }

        if (empty($data['username'])) {
            $errors['username'] = 'Username is required';
        }

        // Password is optional

        if (isset($data['ssh_port']) && ! empty($data['ssh_port'])) {
            if (! is_numeric($data['ssh_port'])) {
                $errors['ssh_port'] = 'SSH port must be a number';
            } elseif ($data['ssh_port'] < 1 || $data['ssh_port'] > 65535) {
                $errors['ssh_port'] = 'SSH port must be between 1 and 65535';
            }
        }

        // If SSH host is provided, username is required
        if (! empty($data['ssh_host']) && empty($data['ssh_username'])) {
            $errors['ssh_username'] = 'SSH username is required when SSH host is provided';
        }

        // If SSH authentication is configured, either password or key path is required
        if (! empty($data['ssh_host']) && empty($data['ssh_password']) && empty($data['ssh_key_path'])) {
            $errors['ssh_auth'] = 'Either SSH password or SSH key path is required when SSH host is provided';
        }

        return $errors;
    }

    /**
     * Import server configuration from array.
     */
    public function import(array $config): Server
    {
        $validated = $this->validate($config);

        if (! empty($validated)) {
            throw new \InvalidArgumentException('Invalid server configuration: '.implode(', ', $validated));
        }

        return $this->create($config);
    }

    /**
     * Export server configuration to array (without sensitive data).
     */
    public function export(Server $server, bool $includeSensitive = false): array
    {
        $data = [
            'name' => $server->name,
            'host' => $server->host,
            'port' => $server->port,
            'username' => $server->username,
            'database' => $server->database,
            'charset' => $server->charset,
            'collation' => $server->collation,
            'is_default' => $server->is_default,
        ];

        if ($includeSensitive) {
            $data['password'] = $server->password;
        }

        if ($server->hasSshTunnel()) {
            $data['ssh_host'] = $server->ssh_host;
            $data['ssh_port'] = $server->ssh_port;
            $data['ssh_username'] = $server->ssh_username;

            if ($includeSensitive) {
                $data['ssh_password'] = $server->ssh_password;
                $data['ssh_key_path'] = $server->ssh_key_path;
            }
        }

        if ($server->options) {
            $data['options'] = $server->options;
        }

        return $data;
    }

    /**
     * Duplicate a server with a new name.
     */
    public function duplicate(Server $server, string $newName): Server
    {
        $data = $this->export($server, true);
        $data['name'] = $newName;
        $data['is_default'] = false;

        return $this->create($data);
    }
}
