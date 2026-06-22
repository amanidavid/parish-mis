<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'customer_contracts';
    private const COLUMN = 'status';
    private const ALLOWED_STATUSES = ['draft', 'active', 'expired', 'terminated'];
    private const ROLLBACK_STATUSES = ['draft', 'active', 'expired', 'terminated', 'renewed'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        DB::table(self::TABLE)
            ->where(self::COLUMN, 'renewed')
            ->update([
                self::COLUMN => 'active',
                'updated_at' => now(),
            ]);

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $this->replacePostgresStatusCheck(self::ALLOWED_STATUSES);

            return;
        }

        if ($driver === 'mysql') {
            $this->modifyMySqlEnum(self::ALLOWED_STATUSES);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $this->replacePostgresStatusCheck(self::ROLLBACK_STATUSES);

            return;
        }

        if ($driver === 'mysql') {
            $this->modifyMySqlEnum(self::ROLLBACK_STATUSES);
        }
    }

    /**
     * Replace PostgreSQL check constraint for contract status.
     */
    private function replacePostgresStatusCheck(array $statuses): void
    {
        $constraints = DB::table('information_schema.table_constraints as tc')
            ->join('information_schema.constraint_column_usage as ccu', function ($join) {
                $join->on('ccu.constraint_name', '=', 'tc.constraint_name')
                    ->on('ccu.table_schema', '=', 'tc.table_schema');
            })
            ->where('tc.constraint_type', 'CHECK')
            ->where('tc.table_schema', DB::getConfig('search_path') ?: 'public')
            ->where('tc.table_name', self::TABLE)
            ->where('ccu.column_name', self::COLUMN)
            ->pluck('tc.constraint_name');

        foreach ($constraints as $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s"',
                self::TABLE,
                $constraint
            ));
        }

        DB::statement(sprintf(
            'ALTER TABLE "%s" ALTER COLUMN "%s" TYPE VARCHAR(20) USING "%s"::text',
            self::TABLE,
            self::COLUMN,
            self::COLUMN
        ));

        DB::statement(sprintf(
            'ALTER TABLE "%s" ALTER COLUMN "%s" SET DEFAULT %s',
            self::TABLE,
            self::COLUMN,
            DB::getPdo()->quote('draft')
        ));

        $allowed = implode(', ', array_map(
            static fn (string $status) => DB::getPdo()->quote($status),
            $statuses
        ));

        DB::statement(sprintf(
            'ALTER TABLE "%s" ADD CONSTRAINT "%s" CHECK ("%s" IN (%s))',
            self::TABLE,
            self::TABLE.'_status_check',
            self::COLUMN,
            $allowed
        ));
    }

    /**
     * Modify MySQL enum values for contract status.
     */
    private function modifyMySqlEnum(array $statuses): void
    {
        $allowed = implode(', ', array_map(
            static fn (string $status) => DB::getPdo()->quote($status),
            $statuses
        ));

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `%s` ENUM(%s) NOT NULL DEFAULT %s',
            self::TABLE,
            self::COLUMN,
            $allowed,
            DB::getPdo()->quote('draft')
        ));
    }
};
