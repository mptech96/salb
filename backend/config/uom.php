<?php
return [
    'schema_install_approved'=>env('SULB_UOM_SCHEMA_INSTALL_APPROVED',false),
    // No enabling switch exists yet: operational integration has NOT been approved.
    'operational_writes_enabled'=>false,
    'contract_testing_enabled'=>false,
    'operational_enabled'=>env('SULB_UOM_OPERATIONAL_ENABLED',false),
];
