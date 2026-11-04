<?php

/*
 * Known, accepted dangerous drifts between the prod snapshot and the migrated test schema.
 * Key format: "<table>.<column>|<kind>" where kind is on_update, nullability or type_family.
 * Value: one-line reason. NEW drift not listed here fails SchemaDriftTest; an entry that
 * no longer matches any drift also fails (remove it when the drift is gone).
 *
 * Seeded 2026-10-14 from the prod snapshot tests/Fixtures/schema/prod-columns.json.
 */
return [
    'attendances.updated_at|on_update' => 'attendances.updated_at on update - legacy prod table; prod has ON UPDATE CURRENT_TIMESTAMP, migrations do not; data fixes must set updated_at=updated_at',
    'attendances.updated_at|nullability' => 'attendances.updated_at NOT NULL in prod (legacy table), nullable in migrations; data fixes must set updated_at=updated_at',
    'attendances.created_at|nullability' => 'attendances.created_at NOT NULL DEFAULT CURRENT_TIMESTAMP in prod (legacy table), nullable in migrations',
    'monitoring_alert_events.entity_id|nullability' => 'prod column is nullable, migrations declare NOT NULL; code must not assume prod rows have entity_id',
    'olt_onus.olt_id|nullability' => 'prod NOT NULL, migrated schema nullable (olt_onus has a test-compat migration, see OltOnusSchemaTest)',
    'olt_onus.onu_ref|nullability' => 'prod NOT NULL, migrated schema nullable (olt_onus has a test-compat migration, see OltOnusSchemaTest)',
];
