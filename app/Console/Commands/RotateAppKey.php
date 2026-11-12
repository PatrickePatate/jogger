<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

#[Signature('jogger:rotate-key {--new-key= : base64:… key to rotate to (default: generate one)} {--write : Replace APP_KEY in .env}')]
#[Description('Re-encrypt every encrypted column with a new application key')]
final class RotateAppKey extends Command
{
    /** table => [primary key, encrypted columns]. Keep in sync with the models' `encrypted` casts. */
    public const array COLUMNS = [
        'github_apps' => ['id', ['client_secret', 'private_key', 'webhook_secret']],
        'secrets' => ['id', ['value', 'previous_value']],
        'notification_rules' => ['id', ['secret']],
        'users' => ['id', ['two_factor_secret', 'two_factor_recovery_codes']],
        'cloud_providers' => ['id', ['credentials']],
    ];

    public function handle(): int
    {
        $cipher = (string) config('app.cipher');
        $current = self::parse((string) config('app.key'));
        $newKey = $this->option('new-key') ?: 'base64:'.base64_encode(Encrypter::generateKey($cipher));
        $old = new Encrypter($current, $cipher);
        $new = new Encrypter(self::parse((string) $newKey), $cipher);

        try {
            $count = DB::transaction(function () use ($old, $new) {
                $count = 0;
                foreach (self::COLUMNS as $table => [$pk, $columns]) {
                    foreach (DB::table($table)->orderBy($pk)->lockForUpdate()->get([$pk, ...$columns]) as $row) {
                        $update = [];
                        foreach ($columns as $column) {
                            if ($row->{$column} !== null && $row->{$column} !== '') {
                                // Unserialized on both sides: the payload is moved as it is, whatever wrote it.
                                $update[$column] = $new->encrypt($old->decrypt($row->{$column}, false), false);
                                $count++;
                            }
                        }
                        if ($update !== []) {
                            DB::table($table)->where($pk, $row->{$pk})->update($update);
                        }
                    }
                }

                return $count;
            });
        } catch (DecryptException $e) {
            $this->error('A value could not be decrypted with the current APP_KEY; nothing was changed. '.$e->getMessage());

            return self::FAILURE;
        }

        // Cached tokens and mask lists were sealed with the old key.
        $this->callSilently('cache:clear');

        if ($this->option('write') && is_writable($env = $this->laravel->environmentFilePath())) {
            file_put_contents($env, (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$newKey, (string) file_get_contents($env)));
            $this->info("{$count} value(s) re-encrypted; APP_KEY replaced in {$env}.");
        } else {
            $this->info("{$count} value(s) re-encrypted.");
            $this->warn('Set this key NOW (the database no longer opens with the old one), then restart every container:');
            $this->line('APP_KEY='.$newKey);
        }
        $this->line('Everyone is signed out: sessions and remember-me cookies were encrypted with the old key.');

        return self::SUCCESS;
    }

    private static function parse(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
