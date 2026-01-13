<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Server extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'password',
        'database',
        'charset',
        'collation',
        'is_default',
        'ssh_host',
        'ssh_port',
        'ssh_username',
        'ssh_password',
        'ssh_key_path',
        'options',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'port' => 'integer',
        'is_default' => 'boolean',
        'ssh_port' => 'integer',
        'options' => 'array',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'ssh_password',
    ];

    /**
     * Get and decrypt the password attribute.
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    /**
     * Get and decrypt the SSH password attribute.
     */
    protected function sshPassword(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }

    /**
     * Scope a query to only include default server.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Set this server as the default server.
     */
    public function setAsDefault(): bool
    {
        // Unset all other default servers
        static::query()->where('is_default', true)->update(['is_default' => false]);

        // Set this server as default
        $this->is_default = true;

        return $this->save();
    }

    /**
     * Get the DSN (Data Source Name) for this server.
     */
    public function getDsn(): string
    {
        $dsn = "mysql:host={$this->host};port={$this->port}";

        if ($this->database) {
            $dsn .= ";dbname={$this->database}";
        }

        $dsn .= ";charset={$this->charset}";

        return $dsn;
    }

    /**
     * Get connection options array.
     */
    public function getConnectionOptions(): array
    {
        return array_merge([
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'database' => $this->database,
            'charset' => $this->charset,
            'collation' => $this->collation,
        ], $this->options ?? []);
    }

    /**
     * Check if SSH tunnel is configured.
     */
    public function hasSshTunnel(): bool
    {
        return ! empty($this->ssh_host);
    }

    /**
     * Get SSH connection options.
     */
    public function getSshOptions(): ?array
    {
        if (! $this->hasSshTunnel()) {
            return null;
        }

        return [
            'host' => $this->ssh_host,
            'port' => $this->ssh_port ?? 22,
            'username' => $this->ssh_username,
            'password' => $this->ssh_password,
            'key_path' => $this->ssh_key_path,
        ];
    }
}
