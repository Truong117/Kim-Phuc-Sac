<?php

namespace App\Console\Commands;

use App\Services\AI\WorkReportAcceptanceFixture;
use App\Support\WorkReportAcceptanceDataset;
use App\Support\WorkReportFixtureManifest;
use App\Support\WorkReportFixtureTarget;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Throwable;

final class WorkReportFixtureCommand extends Command
{
    protected $signature = 'work-report-ai:fixture {--mode=dry-run} {--profile=} {--manifest=} {--batch=wra76-v1} {--end-date=} {--approval=} {--credentials-file=} {--writers-paused}';

    protected $description = 'Prepare, load, verify or clean only the signed synthetic work-report acceptance dataset on verified staging';

    public function handle(): int
    {
        try {
            if (! app()->environment('staging') || config('environment_safety.database_environment') !== 'staging') {
                throw new LogicException('FIXTURE_STAGING_ONLY');
            }
            $mode = $this->option('mode');
            if (! in_array($mode, ['dry-run', 'apply', 'fake-review', 'cleanup-plan', 'cleanup'], true)) {
                throw new LogicException('FIXTURE_INVALID_MODE');
            }
            $profilePath = (string) $this->option('profile');
            WorkReportFixtureManifest::assertExternalPath($profilePath);
            $profile = WorkReportFixtureManifest::readJson($profilePath);
            $connection = (string) config('database.default');
            if (config("database.connections.$connection.driver") !== 'mysql') {
                throw new LogicException('FIXTURE_STAGING_MYSQL_REQUIRED');
            }
            $options = config("database.connections.$connection.options", []);
            if (($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? true) === false) {
                throw new LogicException('FIXTURE_TLS_IDENTITY_REQUIRED');
            }
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            config(["database.connections.$connection.options" => $options]);
            DB::purge($connection); // Dedicated CLI process, reconnect with explicit certificate/hostname verification.
            if (in_array($mode, ['dry-run', 'cleanup-plan'], true)) {
                DB::connection()->beforeExecuting(fn (string $sql) => WorkReportFixtureTarget::assertReadOnlyQuery($sql));
            }
            $fixture = new WorkReportAcceptanceFixture(new WorkReportFixtureTarget($profile), app(WorkReportAcceptanceDataset::class));
            $store = new WorkReportFixtureManifest((string) $this->option('manifest'));
            $batch = (string) $this->option('batch');
            $end = $this->option('end-date');
            if ($mode !== 'dry-run' && ! is_string($end)) {
                throw new LogicException('FIXTURE_APPROVED_END_DATE_REQUIRED');
            }
            $approval = (string) $this->option('approval');
            $credentials = [];
            if ($mode === 'apply' && $this->option('credentials-file')) {
                $path = (string) $this->option('credentials-file');
                WorkReportFixtureManifest::assertExternalPath($path);
                if (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0077) !== 0) {
                    throw new LogicException('FIXTURE_CREDENTIAL_FILE_MUST_BE_PRIVATE');
                }
                $credentials = WorkReportFixtureManifest::readJson($path);
            }
            $result = match ($mode) {
                'dry-run' => $fixture->dryRun($batch, $end, $store),
                'apply' => $fixture->apply($batch, $end, $store, $approval, $credentials),
                'fake-review' => $fixture->fakeReview($batch, $end, $store, $approval),
                'cleanup-plan' => $fixture->cleanupPlan($batch, $end, $store),
                'cleanup' => $fixture->cleanup($batch, $end, $store, $approval, (bool) $this->option('writers-paused')),
            };
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // No SQL, connection strings, credentials, input text or exception trace in command output.
            $code = $exception instanceof LogicException && preg_match('/\AFIXTURE_[A-Z0-9_,]+\z/D', $exception->getMessage()) ? $exception->getMessage() : 'FIXTURE_OPERATION_REFUSED';
            $failure = ['status' => 'FAIL', 'code' => $code];
            if ($code === 'FIXTURE_OPERATION_REFUSED') {
                $failure['exception_type'] = class_basename($exception);
                if ($exception instanceof QueryException) {
                    $failure['sqlstate'] = $exception->errorInfo[0] ?? null;
                    $failure['driver_code'] = $exception->errorInfo[1] ?? null;
                }
            }
            $this->line(json_encode($failure));

            return self::FAILURE;
        }
    }
}
