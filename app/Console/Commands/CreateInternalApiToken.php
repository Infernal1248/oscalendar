<?php

namespace App\Console\Commands;

use App\Models\InternalApiToken;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateInternalApiToken extends Command
{
    protected $signature = 'internal-token:create
        {name : Human-readable token name}
        {--ability=* : Optional ability labels for future HMAC/scope checks}
        {--expires-at= : Optional expiration datetime, stored as UTC}';

    protected $description = 'Create an internal API Bearer token and store only its hash.';

    public function handle(): int
    {
        $plainToken = Str::random(80);

        InternalApiToken::query()->create([
            'name' => $this->argument('name'),
            'token_hash' => InternalApiToken::hashToken($plainToken),
            'abilities' => $this->option('ability') ?: null,
            'expires_at' => $this->option('expires-at') ?: null,
            'is_active' => true,
        ]);

        $this->info('Internal API token created. Copy it now; only the hash is stored.');
        $this->line($plainToken);

        return self::SUCCESS;
    }
}
