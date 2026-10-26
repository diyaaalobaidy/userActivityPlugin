<?php

/**
 * @file plugins/generic/userActivityPlugin/classes/migration/UserActivitySchemaMigration.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class UserActivitySchemaMigration
 * @ingroup plugins_generic_userActivityPlugin
 *
 * @brief Database migration for creating the user_activity_logs table to store
 *        all system-wide user activities (logins, logouts, auth failures,
 *        all database INSERT/UPDATE/DELETE/REPLACE actions, and domain events).
 */

namespace APP\plugins\generic\userActivityPlugin\classes\migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UserActivitySchemaMigration extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('user_activity_logs')) {
            Schema::create('user_activity_logs', function (Blueprint $table) {
                $table->bigIncrements('activity_id');
                $table->bigInteger('user_id')->nullable();
                $table->string('username', 255)->nullable();
                $table->string('user_full_name', 255)->nullable();
                $table->string('user_email', 255)->nullable();
                $table->string('user_roles', 500)->nullable();
                $table->bigInteger('context_id')->nullable();
                $table->string('context_path', 120)->nullable();
                $table->string('session_id', 128)->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('event_category', 64)->default('database'); // auth, database, submission, user, publication, review, file, email, system
                $table->string('event_type', 100)->default('DB_QUERY'); // LOGIN, LOGOUT, LOGIN_FAILED, DB_INSERT, DB_UPDATE, DB_DELETE, etc.
                $table->string('action_summary', 500);
                $table->string('table_name', 128)->nullable();
                $table->string('operation', 32)->nullable(); // INSERT, UPDATE, DELETE, REPLACE, AUTH, ACTION
                $table->string('record_id', 128)->nullable();
                $table->string('http_method', 16)->nullable();
                $table->text('request_url')->nullable();
                $table->longText('old_values')->nullable();
                $table->longText('new_values')->nullable();
                $table->longText('where_conditions')->nullable();
                $table->longText('sql_query')->nullable();
                $table->longText('request_payload')->nullable();
                $table->longText('metadata')->nullable();
                $table->dateTime('created_at')->useCurrent();

                $table->index(['user_id'], 'ual_user_id_idx');
                $table->index(['username'], 'ual_username_idx');
                $table->index(['context_id'], 'ual_context_id_idx');
                $table->index(['event_category'], 'ual_category_idx');
                $table->index(['event_type'], 'ual_type_idx');
                $table->index(['table_name'], 'ual_table_idx');
                $table->index(['operation'], 'ual_operation_idx');
                $table->index(['ip_address'], 'ual_ip_idx');
                $table->index(['created_at'], 'ual_created_at_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');
    }
}
