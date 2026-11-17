<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['demo.email' => 'reviewer@example.test', 'demo.password' => 'Assessment!Demo2026']);
});

test('provisioning creates one verified account with a hashed password without sending mail', function () {
    Notification::fake();

    $this->artisan('demo:provision')->expectsOutput('Verified demo account created.')->assertSuccessful();

    $user = User::query()->sole();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->password)->not->toBe(config('demo.password'))
        ->and(Hash::check(config('demo.password'), $user->password))->toBeTrue();
    Notification::assertNothingSent();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => config('demo.password')])
        ->assertRedirect('/generations');
    $this->get(route('generations.index'))->assertOk();
});

test('repeated startup preserves the account and its password hash', function () {
    $this->artisan('demo:provision')->assertSuccessful();
    $before = User::query()->sole()->getAttributes();

    $this->artisan('demo:provision')->assertSuccessful();

    expect(User::query()->sole()->getAttributes())->toBe($before);
});

test('provisioning never verifies or resets an existing account', function (bool $verified) {
    $user = User::factory()->create([
        'email' => 'Reviewer@example.test',
        'email_verified_at' => $verified ? now() : null,
        'password' => $verified ? 'Different!Password2026' : config('demo.password'),
    ]);
    $before = $user->fresh()->getAttributes();

    $this->artisan('demo:provision')->assertFailed();

    expect($user->fresh()->getAttributes())->toBe($before);
})->with([true, false]);

test('missing or invalid runtime secrets create no account', function (mixed $email, mixed $password) {
    config(['demo.email' => $email, 'demo.password' => $password]);

    $this->artisan('demo:provision')->assertFailed();

    $this->assertDatabaseCount('users', 0);
})->with([
    [null, null],
    ['reviewer@example.test', null],
    [null, 'Assessment!Demo2026'],
    ['invalid-email', 'Assessment!Demo2026'],
    ['reviewer@example.test', 'weak'],
]);

test('ordinary registrations remain unverified and cannot access generations', function () {
    $this->post(route('register.store'), [
        'name' => 'Ordinary User', 'email' => 'ordinary@example.test',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect('/generations');

    expect(User::query()->sole()->hasVerifiedEmail())->toBeFalse();
    $this->get(route('generations.index'))->assertRedirect(route('verification.notice'));
});
