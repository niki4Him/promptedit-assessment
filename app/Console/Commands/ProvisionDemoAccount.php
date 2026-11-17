<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ProvisionDemoAccount extends Command
{
    protected $signature = 'demo:provision';

    protected $description = 'Create a verified reviewer account from runtime secrets without changing existing accounts';

    public function handle(): int
    {
        $email = config('demo.email');
        $password = config('demo.password');
        $email = is_string($email) ? strtolower(trim($email)) : $email;

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'ascii', 'max:72', Password::min(16)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            $this->error('Set DEMO_ACCOUNT_EMAIL and DEMO_ACCOUNT_PASSWORD to a valid email and a 16–72 character ASCII password containing upper/lowercase letters, a number, and a symbol.');

            return self::FAILURE;
        }

        $created = DB::transaction(function () use ($email, $password): ?bool {
            $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($existing !== null) {
                // Never verify, reset, or take over an account that already exists.
                return $existing->hasVerifiedEmail() && Hash::check($password, $existing->password)
                    ? false
                    : null;
            }

            $user = new User(['name' => 'PromptEdit Reviewer', 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->saveOrFail();

            return true;
        });

        if ($created === null) {
            $this->error('An account already exists with that email. No changes were made. Use a new demo email address.');

            return self::FAILURE;
        }

        $this->info($created ? 'Verified demo account created.' : 'Verified account already exists with the supplied credentials. No changes made.');

        return self::SUCCESS;
    }
}
