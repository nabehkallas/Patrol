<?php

namespace Tests\Feature;

use App\Services\StationBackupRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The app's own security header (the Content-Security-Policy with a per-request nonce; nginx
 * adds HSTS and the rest), and the pruning of old pre-restore snapshots.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_carry_a_content_security_policy_whose_nonce_the_inline_script_uses(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $csp);

        preg_match("/'nonce-([^']+)'/", $csp, $match);
        $response->assertSee('<script nonce="'.$match[1].'">', false);
    }

    public function test_each_station_keeps_its_three_newest_restore_snapshots(): void
    {
        $root = storage_path('framework/testing/snapshots-'.uniqid());
        config(['tenancy.database.sqlite_path' => $root]);
        $dir = StationBackupRestorer::snapshotDirectory();
        File::ensureDirectoryExists($dir);

        $a = 'aaaaaaaa-0000-0000-0000-000000000000';
        $b = 'bbbbbbbb-0000-0000-0000-000000000000';
        foreach (['20261001-100000', '20261002-100000', '20261003-100000', '20261004-100000', '20261005-100000'] as $time) {
            touch("$dir/$a-before-restore-$time.sqlite");
        }
        foreach (['20261001-100000', '20261002-100000'] as $time) {
            touch("$dir/$b-before-restore-$time.sqlite");
        }

        $this->assertSame(2, StationBackupRestorer::pruneSnapshots());

        $left = collect(File::files($dir))->map(fn ($file) => $file->getFilename())->sort()->values()->all();
        $this->assertSame([
            "$a-before-restore-20261003-100000.sqlite",
            "$a-before-restore-20261004-100000.sqlite",
            "$a-before-restore-20261005-100000.sqlite",
            "$b-before-restore-20261001-100000.sqlite",
            "$b-before-restore-20261002-100000.sqlite",
        ], $left);

        File::deleteDirectory($root);
    }
}
