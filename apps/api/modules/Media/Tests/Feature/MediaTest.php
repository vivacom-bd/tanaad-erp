<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Media\Models\Media;

beforeEach(function (): void {
    Storage::fake('media');
    Storage::fake('hr');
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->manager = $token('u_sm_bags');
    $this->cashier = $token('u_cash_mats');
    $this->png = fn (string $name = 'a.png') => UploadedFile::fake()->image($name, 10, 10);
});

it('stores a photo content-addressed and dedupes the second upload', function (): void {
    $file = ($this->png)();
    $hash = hash_file('sha256', $file->getRealPath());
    $r = $this->withToken($this->cashier)->post('/api/v1/media', ['file' => $file, 'purpose' => 'photo'], ['Accept' => 'application/json'])->assertCreated();
    expect($r->json('hash'))->toBe($hash)->and($r->json('purpose'))->toBe('photo')->and($r->json('mime'))->toBe('image/png');
    Storage::disk('media')->assertExists(substr($hash, 0, 2).'/'.$hash);

    $again = $this->withToken($this->cashier)->post('/api/v1/media', ['file' => $file, 'purpose' => 'photo'], ['Accept' => 'application/json'])->assertOk();
    expect($again->json('hash'))->toBe($hash)->and(Media::query()->count())->toBe(1);
});

it('rejects a wrong mime type and an oversize file', function (): void {
    $this->withToken($this->cashier)->post('/api/v1/media', ['file' => UploadedFile::fake()->create('a.txt', 1, 'text/plain'), 'purpose' => 'document'], ['Accept' => 'application/json'])->assertStatus(422);
    $this->withToken($this->cashier)->post('/api/v1/media', ['file' => UploadedFile::fake()->create('a.pdf', 9000, 'application/pdf'), 'purpose' => 'document'], ['Accept' => 'application/json'])->assertStatus(422);
    expect(Media::query()->count())->toBe(0);
});

it('keeps HR files on the hr disk for site managers only', function (): void {
    $this->withToken($this->cashier)->post('/api/v1/media', ['file' => ($this->png)(), 'purpose' => 'hr_id'], ['Accept' => 'application/json'])
        ->assertForbidden()->assertJsonPath('code', 'forbidden');
    $r = $this->withToken($this->manager)->post('/api/v1/media', ['file' => ($this->png)(), 'purpose' => 'hr_face'], ['Accept' => 'application/json'])->assertCreated();
    $hash = $r->json('hash');
    Storage::disk('hr')->assertExists(substr($hash, 0, 2).'/'.$hash);
    Storage::disk('media')->assertMissing(substr($hash, 0, 2).'/'.$hash);

    $this->withToken($this->cashier)->getJson("/api/v1/media/{$hash}")->assertForbidden();
    $this->withToken($this->manager)->get("/api/v1/media/{$hash}")->assertOk();
});

it('returns a url shape for reads and 404 for unknown hashes', function (): void {
    $this->getJson('/api/v1/media/'.str_repeat('a', 64))->assertUnauthorized();
    $hash = $this->withToken($this->cashier)->post('/api/v1/media', ['file' => ($this->png)(), 'purpose' => 'photo'], ['Accept' => 'application/json'])->json('hash');
    Storage::shouldReceive('disk')->with('media')->andReturn($fake = Mockery::mock());
    $fake->shouldReceive('temporaryUrl')->once()->andReturn('https://minio.test/signed');
    $this->withToken($this->cashier)->getJson("/api/v1/media/{$hash}")->assertOk()
        ->assertJsonPath('url', 'https://minio.test/signed')->assertJsonStructure(['url', 'expires_at']);
    $this->withToken($this->cashier)->getJson('/api/v1/media/'.str_repeat('0', 64))->assertNotFound();
});
