<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Spatie's model_has_roles / model_has_permissions create model_uuid
     * as varchar, but users.id is a real uuid. Postgres refuses to compare
     * uuid = varchar, so every role-filtered query (User::role(), the role
     * filter dropdowns, the department-leader picker) throws. Convert the
     * column to uuid so the comparison works.
     */
    public function up(): void
    {
        // --- model_has_roles ---
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT model_has_roles_pkey');
        DB::statement('DROP INDEX IF EXISTS model_has_roles_model_id_model_type_index');
        DB::statement('ALTER TABLE model_has_roles ALTER COLUMN model_uuid TYPE uuid USING model_uuid::uuid');
        DB::statement('CREATE INDEX model_has_roles_model_id_model_type_index ON model_has_roles (model_uuid, model_type)');
        DB::statement('ALTER TABLE model_has_roles ADD PRIMARY KEY (role_id, model_uuid, model_type)');

        // --- model_has_permissions (same structure; fix it too) ---
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT model_has_permissions_pkey');
        DB::statement('DROP INDEX IF EXISTS model_has_permissions_model_id_model_type_index');
        DB::statement('ALTER TABLE model_has_permissions ALTER COLUMN model_uuid TYPE uuid USING model_uuid::uuid');
        DB::statement('CREATE INDEX model_has_permissions_model_id_model_type_index ON model_has_permissions (model_uuid, model_type)');
        DB::statement('ALTER TABLE model_has_permissions ADD PRIMARY KEY (permission_id, model_uuid, model_type)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT model_has_roles_pkey');
        DB::statement('DROP INDEX IF EXISTS model_has_roles_model_id_model_type_index');
        DB::statement('ALTER TABLE model_has_roles ALTER COLUMN model_uuid TYPE varchar(255) USING model_uuid::text');
        DB::statement('CREATE INDEX model_has_roles_model_id_model_type_index ON model_has_roles (model_uuid, model_type)');
        DB::statement('ALTER TABLE model_has_roles ADD PRIMARY KEY (role_id, model_uuid, model_type)');

        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT model_has_permissions_pkey');
        DB::statement('DROP INDEX IF EXISTS model_has_permissions_model_id_model_type_index');
        DB::statement('ALTER TABLE model_has_permissions ALTER COLUMN model_uuid TYPE varchar(255) USING model_uuid::text');
        DB::statement('CREATE INDEX model_has_permissions_model_id_model_type_index ON model_has_permissions (model_uuid, model_type)');
        DB::statement('ALTER TABLE model_has_permissions ADD PRIMARY KEY (permission_id, model_uuid, model_type)');
    }
};
