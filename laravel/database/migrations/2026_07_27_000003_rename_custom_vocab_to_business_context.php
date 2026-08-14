<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('custom_vocab', 'business_context');

        Schema::table('business_context', function (Blueprint $table) {
            $table->renameColumn('term', 'title');
            $table->renameColumn('definition', 'content');
        });

        // Preserve legacy synonyms by folding them into the content body
        // before the column is dropped.
        foreach (DB::table('business_context')->get(['id', 'content', 'synonyms']) as $row) {
            $synonyms = json_decode($row->synonyms ?? 'null', true);
            if (!is_array($synonyms) || $synonyms === []) {
                continue;
            }
            DB::table('business_context')->where('id', $row->id)->update([
                'content' => trim(($row->content ?? '') . "\n\nSynonymes : " . implode(', ', $synonyms)),
            ]);
        }

        Schema::table('business_context', function (Blueprint $table) {
            $table->text('content')->change();
            $table->dropColumn('synonyms');
            $table->string('scope_table', 191)->nullable()->after('content');
            $table->string('scope_column', 191)->nullable()->after('scope_table');
            $table->json('tags')->nullable()->after('scope_column');
            $table->smallInteger('priority')->default(0)->after('tags');
            $table->date('starts_on')->nullable()->after('priority');
            $table->date('ends_on')->nullable()->after('starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('business_context', function (Blueprint $table) {
            $table->dropColumn(['scope_table', 'scope_column', 'tags', 'priority', 'starts_on', 'ends_on']);
            $table->json('synonyms')->nullable()->after('content');
            $table->string('content', 500)->change();
            $table->renameColumn('content', 'definition');
            $table->renameColumn('title', 'term');
        });

        Schema::rename('business_context', 'custom_vocab');
    }
};
