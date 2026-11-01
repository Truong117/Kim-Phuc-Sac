<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class AccountEmailTransaction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(Closure $operation): mixed
    {
        $connection = DB::connection();
        $initialLevel = $connection->transactionLevel();
        $connection->beginTransaction();
        $committing = false;

        try {
            $result = $operation();
            $committing = true;
            $connection->commit();

            return $result;
        } catch (Throwable $error) {
            // A post-commit callback must never be classified as a failed database write.
            if ($committing && $connection->transactionLevel() === $initialLevel) {
                throw $error;
            }

            // Manual commit preserves the level on PDO failure, so this also clears
            // pending callbacks and any still-active transaction after a failed COMMIT.
            $connection->rollBack($initialLevel);

            if ($error instanceof PDOException) {
                $state = (string) ($error->errorInfo[0] ?? $error->getCode());
                $number = (int) ($error->errorInfo[1] ?? 0);
                $detail = (string) ($error->errorInfo[2] ?? '');
                $mysqlEmailUnique = $state === '23000' && $number === 1062
                    && preg_match('/for key [\'`"](?:[a-zA-Z0-9_]+\.)?users_email_unique[\'`"]\s*$/i', $detail);
                $sqliteEmailUnique = $state === '23000' && in_array($number, [19, 2067], true)
                    && $detail === 'UNIQUE constraint failed: users.email';

                if ($mysqlEmailUnique || $sqliteEmailUnique) {
                    throw ValidationException::withMessages(['email' => ['Email đã được sử dụng.']]);
                }

                if ($state === '40001' || ($state === 'HY000' && in_array($number, [1205, 9007], true))) {
                    // No automatic retries: the caller can retry after a confirmed rollback.
                    throw new ServiceUnavailableHttpException(
                        1,
                        'Không thể hoàn tất cập nhật do xung đột giao dịch. Vui lòng thử lại.',
                        $error,
                    );
                }
            }

            // Connection loss and unknown failures are not evidence of a duplicate email.
            throw $error;
        }
    }
}
