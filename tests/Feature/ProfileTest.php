<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyNewEmailNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

/** PATCH /me: the app's profile screen (names, mobile, reminders, email change with verification). */
class ProfileTest extends ApiTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Bittu Kumar', 'email' => 'bittu01@yopmail.com']);
        $this->signIn($this->user);
    }

    public function test_existing_name_is_split_and_new_fields_have_defaults(): void
    {
        $this->getJson('/v1/me')->assertOk()
            ->assertJsonPath('name', 'Bittu Kumar')
            ->assertJsonPath('firstName', 'Bittu')->assertJsonPath('lastName', 'Kumar')
            ->assertJsonPath('preferredName', null)->assertJsonPath('mobile', null)
            ->assertJsonPath('remindersEnabled', true)->assertJsonPath('pendingEmail', null);
    }

    public function test_update_names_mobile_and_reminders(): void
    {
        $this->patchJson('/v1/me', [
            'firstName' => ' bittu ', 'lastName' => '', 'preferredName' => 'Bit', 'mobile' => '0412 345 678', 'remindersEnabled' => false,
        ])->assertOk()
            ->assertJsonPath('firstName', 'bittu')->assertJsonPath('lastName', null)->assertJsonPath('name', 'bittu')
            ->assertJsonPath('preferredName', 'Bit')->assertJsonPath('mobile', '+61412345678')
            ->assertJsonPath('remindersEnabled', false);

        // Only what is sent changes.
        $this->patchJson('/v1/me', ['lastName' => 'Sharma'])->assertOk()
            ->assertJsonPath('name', 'bittu Sharma')->assertJsonPath('mobile', '+61412345678')->assertJsonPath('preferredName', 'Bit');

        foreach (['+61 412 345 678', '61412345678', '(04) 1234-5678'] as $format) {
            $this->patchJson('/v1/me', ['mobile' => $format])->assertOk()->assertJsonPath('mobile', '+61412345678');
        }
        $this->patchJson('/v1/me', ['mobile' => null])->assertOk()->assertJsonPath('mobile', null);
        $this->patchJson('/v1/me', ['mobile' => '04XX XXX XXX'])->assertStatus(422)->assertJsonPath('error.field', 'mobile');
        $this->patchJson('/v1/me', ['mobile' => '0298765432'])->assertStatus(422)->assertJsonPath('error.field', 'mobile'); // landline
        $this->patchJson('/v1/me', ['firstName' => ''])->assertStatus(422)->assertJsonPath('error.field', 'firstName');
        $this->patchJson('/v1/me', ['remindersEnabled' => 'maybe'])->assertStatus(422)->assertJsonPath('error.field', 'remindersEnabled');
    }

    public function test_save_changes_in_one_multipart_request_with_a_photo(): void
    {
        Storage::fake('local');
        Notification::fake();

        $res = $this->post('/v1/me', [
            'firstName' => 'Bittu', 'lastName' => 'Sharma', 'preferredName' => 'Bit',
            'email' => 'bittu.new@example.com', 'phone' => '0412 345 678', 'remindersEnabled' => 'false',
            'photo' => UploadedFile::fake()->image('me.jpg', 600, 600),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('firstName', 'Bittu')->assertJsonPath('lastName', 'Sharma')->assertJsonPath('name', 'Bittu Sharma')
            ->assertJsonPath('email', 'bittu01@yopmail.com')->assertJsonPath('pendingEmail', 'bittu.new@example.com')
            ->assertJsonPath('mobile', '+61412345678')->assertJsonPath('remindersEnabled', false);
        $this->assertMatchesRegularExpression('#/avatars/[A-Za-z0-9]{40}\.jpg$#', $res->json('photoUrl'));
        Notification::assertSentTimes(VerifyNewEmailNotification::class, 1);
    }

    public function test_patch_with_form_data_is_rejected_clearly(): void
    {
        $this->call('PATCH', '/v1/me', [], [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(415)->assertJsonPath('error.code', 'use_post_for_form_data');
    }

    public function test_a_bad_photo_saves_nothing(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->post('/v1/me', [
            'firstName' => 'Changed', 'email' => 'other@example.com',
            'photo' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_photo');

        $fresh = $this->user->fresh();
        $this->assertSame('Bittu', $fresh->first_name);
        $this->assertNull($fresh->pending_email);
        Notification::assertNothingSent();
    }

    public function test_older_app_builds_can_still_send_name(): void
    {
        $this->patchJson('/v1/me', ['name' => 'Asha Devi Patel'])->assertOk()
            ->assertJsonPath('name', 'Asha Devi Patel')->assertJsonPath('firstName', 'Asha')->assertJsonPath('lastName', 'Devi Patel');
    }

    public function test_email_change_waits_for_the_link_sent_to_the_new_address(): void
    {
        Notification::fake();

        $this->patchJson('/v1/me', ['email' => 'New@Example.com'])->assertOk()
            ->assertJsonPath('email', 'bittu01@yopmail.com')->assertJsonPath('pendingEmail', 'new@example.com');

        $link = null;
        Notification::assertSentTo(new AnonymousNotifiable, VerifyNewEmailNotification::class, function ($n, $channels, $notifiable) use (&$link) {
            $link = $n->toMail($notifiable)->actionUrl;

            return $notifiable->routes['mail'] === 'new@example.com';
        });

        // Same pending address again: no second email.
        $this->patchJson('/v1/me', ['email' => 'new@example.com'])->assertOk();
        Notification::assertSentTimes(VerifyNewEmailNotification::class, 1);

        // Old email still signs in until the link is opened; a reset link for it stops working afterwards.
        $resetToken = Password::broker()->createToken($this->user);
        $this->get($link)->assertOk()->assertSee('Your email has been changed.');
        $this->assertSame('new@example.com', $this->user->fresh()->email);
        $this->assertNull($this->user->fresh()->pending_email);
        $this->assertFalse(Password::broker()->tokenExists($this->user->fresh(), $resetToken));

        // The link works once.
        $this->get($link)->assertOk()->assertSee('This link has expired or was already used.');
    }

    public function test_email_change_rules(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->patchJson('/v1/me', ['email' => 'taken@example.com'])->assertStatus(409)->assertJsonPath('error.code', 'email_taken');
        $this->patchJson('/v1/me', ['email' => 'not-an-email'])->assertStatus(422)->assertJsonPath('error.field', 'email');

        // Only the newest link works; changing back to the current email cancels.
        $this->patchJson('/v1/me', ['email' => 'first@example.com'])->assertOk();
        $first = VerifyNewEmailNotification::link($this->user->fresh(), 'first@example.com');
        $this->patchJson('/v1/me', ['email' => 'second@example.com'])->assertOk()->assertJsonPath('pendingEmail', 'second@example.com');
        $this->get($first)->assertSee('This link has expired or was already used.');
        $this->patchJson('/v1/me', ['email' => 'bittu01@yopmail.com'])->assertOk()->assertJsonPath('pendingEmail', null);

        // Expired after 60 minutes.
        $this->patchJson('/v1/me', ['email' => 'late@example.com'])->assertOk();
        $late = VerifyNewEmailNotification::link($this->user->fresh(), 'late@example.com');
        $this->travel(61)->minutes();
        $this->get($late)->assertSee('This link has expired or was already used.');
        $this->assertSame('bittu01@yopmail.com', $this->user->fresh()->email);

        // Tampered link.
        $this->travelBack();
        $this->get(str_replace('signature=', 'signature=x', VerifyNewEmailNotification::link($this->user->fresh(), 'late@example.com')))
            ->assertSee('This link has expired or was already used.');
    }

    public function test_someone_else_takes_the_address_before_the_link_is_opened(): void
    {
        Notification::fake();
        $this->patchJson('/v1/me', ['email' => 'race@example.com'])->assertOk();
        $link = VerifyNewEmailNotification::link($this->user->fresh(), 'race@example.com');
        User::factory()->create(['email' => 'race@example.com']);

        $this->get($link)->assertSee('This email is already used by another account.');
        $this->assertSame('bittu01@yopmail.com', $this->user->fresh()->email);
    }

    public function test_email_changes_are_rate_limited(): void
    {
        Notification::fake();
        foreach (range(1, 5) as $i) {
            $this->patchJson('/v1/me', ['email' => "n{$i}@example.com"])->assertOk();
        }
        $this->patchJson('/v1/me', ['email' => 'n6@example.com'])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }
}
