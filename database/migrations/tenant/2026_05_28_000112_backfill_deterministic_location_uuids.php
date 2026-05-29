<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $this->backfillTable('countries');
        $this->backfillTable('regions');
        $this->backfillTable('districts');
        $this->backfillTable('wards');
    }

    public function down(): void
    {
        // Intentionally left blank because previous UUID values were random and not recoverable.
    }

    private function backfillTable(string $table): void
    {
        DB::table($table)
            ->select(['id', 'legacy_id'])
            ->whereNotNull('legacy_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([
                            'uuid' => $this->stableLocationUuid($table, (int) $row->legacy_id),
                        ]);
                }
            });
    }

    private function stableLocationUuid(string $dataset, int $legacyId): string
    {
        $hash = md5("parish-mis.locations.{$dataset}.{$legacyId}");

        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }
};
