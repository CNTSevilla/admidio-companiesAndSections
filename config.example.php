<?php
/**
 * Companies and Sections - Permissions configuration
 *
 * Copy this file to config.php and list the EXACT names of the Admidio roles of the
 * SECRETARIAT: they see and edit every company. Only CURRENT memberships count.
 * Administrators always have full access. Delegates (marked in an assignment, or
 * section spokespersons) see only their companies, read only, without config.
 * Empty list = only administrators (plus delegates, read only).
 */
return [
    'allowed_roles' => [
        // 'Secretario/a de Organizacion',
        // 'Secretario/a de Tesorería',
    ],
];
