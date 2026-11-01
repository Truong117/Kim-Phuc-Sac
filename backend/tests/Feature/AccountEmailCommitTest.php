<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\Support\CommitFaultPdo;
use Tests\TestCase;

class AccountEmailCommitTest extends TestCase
{
    use AuthorizationFixtures;

    private CommitFaultPdo $commitPdo;

    protected function setUp(): void
    {
        parent::setUp();
        // No RefreshDatabase wrapping transaction: exercise the real PDO COMMIT boundary.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->commitPdo = new CommitFaultPdo('sqlite::memory:');
        DB::connection()->setPdo($this->commitPdo)->setReadPdo($this->commitPdo);
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    public function test_duplicate_email_at_physical_commit_returns_422_and_clears_the_transaction(): void
    {
        $user = $this->employee();
        $callbacks = 0;
        User::saved(static function () use (&$callbacks): void {
            DB::afterCommit(static function () use (&$callbacks): void {
                $callbacks++;
            });
        });
        $this->commitPdo->failNextCommit($this->databaseError('23000', 1062, "Duplicate entry 'new@example.com' for key 'users.users_email_unique'"));
        $this->actingAs($user, 'web')->patchJson('/api/account/email', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 0);
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse($this->commitPdo->inTransaction());
        $this->assertSame(1, $this->commitPdo->commitAttempts);
        $this->assertSame(0, $callbacks);
        // Only an explicit new request retries, and the failed request's callback is gone.
        $this->patchJson('/api/account/email', $this->payload())->assertOk();
        $this->assertSame(2, $this->commitPdo->commitAttempts);
        $this->assertSame(1, $callbacks);
        $this->assertDatabaseCount('account_audit_events', 1);
    }

    #[DataProvider('conflictProvider')]
    public function test_commit_conflicts_are_503_after_rollback_without_automatic_retry(string $state, int $code): void
    {
        $user = $this->employee();
        $this->commitPdo->failNextCommit($this->databaseError($state, $code, 'Simulated transaction conflict'));
        $this->actingAs($user, 'web')->patchJson('/api/account/email', $this->payload())
            ->assertStatus(503)->assertHeader('Retry-After', '1')->assertJsonMissingPath('errors.email');
        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 0);
        $this->assertSame(1, $this->commitPdo->commitAttempts);
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse($this->commitPdo->inTransaction());
    }

    public static function conflictProvider(): array
    {
        return [['40001', 1213], ['HY000', 1205], ['HY000', 9007], ['40001', 0]];
    }

    #[DataProvider('unrelatedErrorProvider')]
    public function test_unrelated_commit_errors_are_never_mapped_to_duplicate_email(string $state, int $code, string $detail): void
    {
        $user = $this->employee();
        $error = $this->databaseError($state, $code, $detail);
        $this->commitPdo->failNextCommit($error);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user, 'web')->patchJson('/api/account/email', $this->payload());
            $this->fail('The original database error must propagate.');
        } catch (PDOException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame('old@example.com', $user->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 0);
        $this->assertSame(1, $this->commitPdo->commitAttempts);
    }

    public static function unrelatedErrorProvider(): array
    {
        return [
            ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'],
            ['23000', 1062, "Duplicate entry 'users_email_unique' for key 'PRIMARY'"],
            ['HY000', 2006, 'MySQL server has gone away'],
            ['HY000', 1105, 'Unknown database failure'],
        ];
    }

    public function test_a_callback_error_after_successful_commit_is_not_mapped_or_retried(): void
    {
        $user = $this->employee();
        $error = $this->databaseError('23000', 1062, "Duplicate entry 'new@example.com' for key 'users_email_unique'");
        User::saved(static function () use ($error): void {
            DB::afterCommit(static function () use ($error): void {
                throw $error;
            });
        });
        $this->commitPdo->commitAttempts = 0;
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user, 'web')->patchJson('/api/account/email', $this->payload());
            $this->fail('The callback error must not be converted to failed email validation.');
        } catch (PDOException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame('new@example.com', $user->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 1);
        $this->assertSame(1, $this->commitPdo->commitAttempts);
    }

    public function test_lost_commit_acknowledgement_never_replays_an_already_committed_write(): void
    {
        $user = $this->employee();
        $error = $this->databaseError('HY000', 2013, 'Lost connection during COMMIT');
        $this->commitPdo->failNextCommit($error, commitBeforeFailure: true);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user, 'web')->patchJson('/api/account/email', $this->payload());
            $this->fail('An uncertain commit must not be converted to 422 or automatically retried.');
        } catch (PDOException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertSame('new@example.com', $user->refresh()->email);
        $this->assertDatabaseCount('account_audit_events', 1);
        $this->assertSame(1, $this->commitPdo->commitAttempts);
    }

    private function employee(): User
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => 'current-password']);
        $this->createMembership($user);

        return $user;
    }

    private function payload(): array
    {
        return ['email' => 'new@example.com', 'current_password' => 'current-password'];
    }

    private function databaseError(string $state, int $code, string $detail): PDOException
    {
        $error = new PDOException($detail);
        $error->errorInfo = [$state, $code, $detail];

        return $error;
    }
}
