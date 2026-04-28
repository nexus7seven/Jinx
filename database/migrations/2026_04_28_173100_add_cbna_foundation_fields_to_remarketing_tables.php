<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateRemarketingSteps();
        $this->updateRemarketingTemplates();
        $this->updateLeadRemarketingStepLogs();
    }

    public function down(): void
    {
        if (Schema::hasTable('lead_remarketing_step_logs')) {
            if ($this->indexExists('lead_remarketing_step_logs', 'lead_remarketing_step_logs_execution_status_index')) {
                Schema::table('lead_remarketing_step_logs', function (Blueprint $table) {
                    $table->dropIndex('lead_remarketing_step_logs_execution_status_index');
                });
            }

            if ($this->indexExists('lead_remarketing_step_logs', 'lead_remarketing_step_logs_lead_id_step_key_index')) {
                Schema::table('lead_remarketing_step_logs', function (Blueprint $table) {
                    $table->dropIndex('lead_remarketing_step_logs_lead_id_step_key_index');
                });
            }

            Schema::table('lead_remarketing_step_logs', function (Blueprint $table) {
                foreach ([
                    'planned_medium',
                    'actual_medium',
                    'planned_template_key',
                    'actual_template_key',
                    'fallback_used',
                    'fallback_reason',
                    'provider',
                    'provider_template_id',
                    'execution_status',
                    'execution_error',
                    'executed_at',
                    'metadata_json',
                ] as $column) {
                    if (Schema::hasColumn('lead_remarketing_step_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('remarketing_templates')) {
            Schema::table('remarketing_templates', function (Blueprint $table) {
                foreach ([
                    'provider_template_id',
                    'preview_text',
                    'body_text',
                    'body_html',
                    'metadata_json',
                ] as $column) {
                    if (Schema::hasColumn('remarketing_templates', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('remarketing_steps')) {
            if ($this->indexExists('remarketing_steps', 'remarketing_steps_flow_key_step_order_index')) {
                Schema::table('remarketing_steps', function (Blueprint $table) {
                    $table->dropIndex('remarketing_steps_flow_key_step_order_index');
                });
            }

            if ($this->indexExists('remarketing_steps', 'remarketing_steps_step_key_index')) {
                Schema::table('remarketing_steps', function (Blueprint $table) {
                    $table->dropIndex('remarketing_steps_step_key_index');
                });
            }

            Schema::table('remarketing_steps', function (Blueprint $table) {
                foreach ([
                    'primary_medium',
                    'primary_template_key',
                    'fallback_medium',
                    'fallback_template_key',
                    'fallback_condition',
                    'sendgrid_template_id',
                    'call_window_label',
                    'call_window_start',
                    'call_window_end',
                    'parent_step_key',
                    'is_manual',
                    'metadata_json',
                ] as $column) {
                    if (Schema::hasColumn('remarketing_steps', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function updateRemarketingSteps(): void
    {
        if (! Schema::hasTable('remarketing_steps')) {
            return;
        }

        Schema::table('remarketing_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('remarketing_steps', 'primary_medium')) {
                $table->string('primary_medium')->nullable()->after('medium');
            }
            if (! Schema::hasColumn('remarketing_steps', 'primary_template_key')) {
                $table->string('primary_template_key')->nullable()->after('primary_medium');
            }
            if (! Schema::hasColumn('remarketing_steps', 'fallback_medium')) {
                $table->string('fallback_medium')->nullable()->after('primary_template_key');
            }
            if (! Schema::hasColumn('remarketing_steps', 'fallback_template_key')) {
                $table->string('fallback_template_key')->nullable()->after('fallback_medium');
            }
            if (! Schema::hasColumn('remarketing_steps', 'fallback_condition')) {
                $table->string('fallback_condition')->nullable()->after('fallback_template_key');
            }
            if (! Schema::hasColumn('remarketing_steps', 'sendgrid_template_id')) {
                $table->string('sendgrid_template_id')->nullable()->after('fallback_condition');
            }
            if (! Schema::hasColumn('remarketing_steps', 'call_window_label')) {
                $table->string('call_window_label')->nullable()->after('sendgrid_template_id');
            }
            if (! Schema::hasColumn('remarketing_steps', 'call_window_start')) {
                $table->time('call_window_start')->nullable()->after('call_window_label');
            }
            if (! Schema::hasColumn('remarketing_steps', 'call_window_end')) {
                $table->time('call_window_end')->nullable()->after('call_window_start');
            }
            if (! Schema::hasColumn('remarketing_steps', 'parent_step_key')) {
                $table->string('parent_step_key')->nullable()->after('call_window_end');
            }
            if (! Schema::hasColumn('remarketing_steps', 'is_manual')) {
                $table->boolean('is_manual')->default(false)->after('parent_step_key');
            }
            if (! Schema::hasColumn('remarketing_steps', 'requires_manual_completion')) {
                $table->boolean('requires_manual_completion')->default(false)->after('is_manual');
            }
            if (! Schema::hasColumn('remarketing_steps', 'auto_advance_on_send')) {
                $table->boolean('auto_advance_on_send')->default(true)->after('requires_manual_completion');
            }
            if (! Schema::hasColumn('remarketing_steps', 'metadata_json')) {
                $table->json('metadata_json')->nullable()->after('auto_advance_on_send');
            }
        });

        if (
            Schema::hasColumn('remarketing_steps', 'flow_key')
            && Schema::hasColumn('remarketing_steps', 'step_order')
            && ! $this->indexExists('remarketing_steps', 'remarketing_steps_flow_key_step_order_index')
        ) {
            Schema::table('remarketing_steps', function (Blueprint $table) {
                $table->index(['flow_key', 'step_order'], 'remarketing_steps_flow_key_step_order_index');
            });
        }

        if (Schema::hasColumn('remarketing_steps', 'step_key')) {
            $hasStepKeyUnique = $this->indexExists('remarketing_steps', 'remarketing_steps_step_key_unique');
            $hasStepKeyIndex = $this->indexExists('remarketing_steps', 'remarketing_steps_step_key_index');

            if (! $hasStepKeyUnique && ! $hasStepKeyIndex) {
                Schema::table('remarketing_steps', function (Blueprint $table) {
                    $table->index('step_key', 'remarketing_steps_step_key_index');
                });
            }
        }
    }

    private function updateRemarketingTemplates(): void
    {
        if (! Schema::hasTable('remarketing_templates')) {
            return;
        }

        Schema::table('remarketing_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('remarketing_templates', 'provider')) {
                $table->string('provider')->nullable()->after('medium');
            }
            if (! Schema::hasColumn('remarketing_templates', 'provider_template_id')) {
                $table->string('provider_template_id')->nullable()->after('provider');
            }
            if (! Schema::hasColumn('remarketing_templates', 'subject')) {
                $table->string('subject')->nullable()->after('provider_template_id');
            }
            if (! Schema::hasColumn('remarketing_templates', 'preview_text')) {
                $table->string('preview_text')->nullable()->after('subject');
            }
            if (! Schema::hasColumn('remarketing_templates', 'body_text')) {
                $table->longText('body_text')->nullable()->after('preview_text');
            }
            if (! Schema::hasColumn('remarketing_templates', 'body_html')) {
                $table->longText('body_html')->nullable()->after('body_text');
            }
            if (! Schema::hasColumn('remarketing_templates', 'metadata_json')) {
                $table->json('metadata_json')->nullable()->after('body_html');
            }
        });
    }

    private function updateLeadRemarketingStepLogs(): void
    {
        if (! Schema::hasTable('lead_remarketing_step_logs')) {
            return;
        }

        Schema::table('lead_remarketing_step_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'planned_medium')) {
                $table->string('planned_medium')->nullable()->after('medium');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'actual_medium')) {
                $table->string('actual_medium')->nullable()->after('planned_medium');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'planned_template_key')) {
                $table->string('planned_template_key')->nullable()->after('actual_medium');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'actual_template_key')) {
                $table->string('actual_template_key')->nullable()->after('planned_template_key');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'fallback_used')) {
                $table->boolean('fallback_used')->default(false)->after('actual_template_key');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'fallback_reason')) {
                $table->string('fallback_reason')->nullable()->after('fallback_used');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'provider')) {
                $table->string('provider')->nullable()->after('fallback_reason');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'provider_template_id')) {
                $table->string('provider_template_id')->nullable()->after('provider');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'provider_message_id')) {
                $table->string('provider_message_id')->nullable()->after('provider_template_id');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'execution_status')) {
                $table->string('execution_status')->nullable()->after('provider_message_id');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'execution_error')) {
                $table->text('execution_error')->nullable()->after('execution_status');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'due_at')) {
                $table->timestamp('due_at')->nullable()->after('execution_error');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'executed_at')) {
                $table->timestamp('executed_at')->nullable()->after('due_at');
            }
            if (! Schema::hasColumn('lead_remarketing_step_logs', 'metadata_json')) {
                $table->json('metadata_json')->nullable()->after('executed_at');
            }
        });

        if (
            Schema::hasColumn('lead_remarketing_step_logs', 'lead_id')
            && Schema::hasColumn('lead_remarketing_step_logs', 'step_key')
            && ! $this->indexExists('lead_remarketing_step_logs', 'lead_remarketing_step_logs_lead_id_step_key_index')
        ) {
            Schema::table('lead_remarketing_step_logs', function (Blueprint $table) {
                $table->index(['lead_id', 'step_key'], 'lead_remarketing_step_logs_lead_id_step_key_index');
            });
        }

        if (
            Schema::hasColumn('lead_remarketing_step_logs', 'due_at')
            && ! $this->indexExists('lead_remarketing_step_logs', 'lead_remarketing_step_logs_due_at_index')
        ) {
            Schema::table('lead_remarketing_step_logs', function (Blueprint $table) {
                $table->index('due_at', 'lead_remarketing_step_logs_due_at_index');
            });
        }

        if (
            Schema::hasColumn('lead_remarketing_step_logs', 'execution_status')
            && ! $this->indexExists('lead_remarketing_step_logs', 'lead_remarketing_step_logs_execution_status_index')
        ) {
            Schema::table('lead_remarketing_step_logs', function (Blueprint $table) {
                $table->index('execution_status', 'lead_remarketing_step_logs_execution_status_index');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        return match ($driver) {
            'mysql' => DB::table('information_schema.statistics')
                ->whereRaw('table_schema = DATABASE()')
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->exists(),
            'pgsql' => DB::table('pg_indexes')
                ->where('schemaname', 'public')
                ->where('tablename', $table)
                ->where('indexname', $indexName)
                ->exists(),
            'sqlite' => collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row) => ($row->name ?? null) === $indexName),
            'sqlsrv' => DB::table('sys.indexes')
                ->join('sys.tables', 'sys.tables.object_id', '=', 'sys.indexes.object_id')
                ->where('sys.tables.name', $table)
                ->where('sys.indexes.name', $indexName)
                ->exists(),
            default => false,
        };
    }
};
