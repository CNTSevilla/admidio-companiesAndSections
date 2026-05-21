<?php
/**
 * Companies and Sections Plugin - Helper Functions
 *
 * This file contains auxiliary functions used by the plugin.
 *
 * FUNCTIONS:
 * - updateOrgMembersDropdown(): Updates the organizations dropdown (legacy)
 * - syncOrgNameInContacts(): Synchronizes the company name in contacts
 *                           when an organization is renamed
 *
 * @package OrganizationMembers
 */


function updateOrgMembersDropdown($crmDb): bool
{
    return true;
}

/**
 * Synchronizes the company name in users' data.
 *
 * When an organization is renamed, this function updates all
 * values of the "Company" field in users' profiles that
 * had the old name.
 *
 * IMPORTANT: This function is called from organization_profile.php
 * when saving a modification to an organization.
 *
 * @param PDO   $crmDb       PDO connection to the database used by the CRM (usually $gDb from Admidio)
 * @param string $oldOrgName Old name of the organization
 * @param string $newOrgName New name of the organization
 * @return int Number of updated records
 */
function syncOrgNameInContacts($crmDb, $oldOrgName, $newOrgName): int
{
    if (empty($oldOrgName) || empty($newOrgName) || $oldOrgName === $newOrgName) {
        return 0;
    }

    try {
        // Obtain the user field ID for "CRM_EMPRESA"
        $stmt = $crmDb->prepare("
            SELECT usf_id, usf_type
            FROM adm_user_fields
            WHERE usf_name_intern = 'CRM_EMPRESA'
            LIMIT 1
        ");
        $stmt->execute();
        $field = $stmt->fetch();

        if (!$field) {
            error_log('syncOrgNameInContacts: Campo CRM_EMPRESA no encontrado');
            return 0;
        }

        $usfId = $field['usf_id'];
        $usfType = $field['usf_type'];

        // If the field is a DROPDOWN (legacy), update the options instead of user data
        if ($usfType === 'DROPDOWN') {
            $stmt = $crmDb->prepare("
                UPDATE adm_user_field_select_options
                SET ufo_value = ?
                WHERE ufo_usf_id = ? AND ufo_value = ?
            ");
            $stmt->execute([$newOrgName, $usfId, $oldOrgName]);
            error_log("syncOrgNameInContacts: Opción dropdown '$oldOrgName' -> '$newOrgName'");
            return 1;
        }

        // If the field is TEXT (current format), update user data
        $stmt = $crmDb->prepare("
            UPDATE adm_user_data
            SET usd_value = ?
            WHERE usd_usf_id = ? AND usd_value = ?
        ");
        $stmt->execute([$newOrgName, $usfId, $oldOrgName]);
        $count = $stmt->rowCount();
        error_log("syncOrgNameInContacts: Actualizados $count registros de '$oldOrgName' a '$newOrgName'");

        return $count;

    } catch (Exception $e) {
        error_log('syncOrgNameInContacts Error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Checks if the current user is authorized to use the plugin.
 * - Administrators always have access.
 * - If `allowed_roles` in config.php is empty => only administrators.
 * - If there are names in `allowed_roles`, users with any of those roles are allowed.
 *
 * @param \Database|null $db Instance of Admidio's database (global $gDb if null)
 * @param int|null $userId ID of the user (if null, uses $gCurrentUser)
 * @return bool
 */
function isUserAuthorized($db = null, $userId = null): bool
{
    global $gCurrentUser, $gDb;

    if ($db === null) {
        $db = $gDb;
    }

    if (!($gCurrentUser ?? null)) {
        return false;
    }

    if ($gCurrentUser->isAdministrator()) {
        return true;
    }

    if ($userId === null) {
        $userId = $gCurrentUser->getValue('usr_id') ?? 0;
    }

    $cfgFile = __DIR__ . '/config.php';
    if (!file_exists($cfgFile)) {
        return false;
    }

    $cfg = include_once $cfgFile;
    $allowed = $cfg['allowed_roles'] ?? [];

    if (empty($allowed)) {
        return false;
    }

    // Search for role IDs by name
    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $stmt = $db->queryPrepared("SELECT rol_id FROM adm_roles WHERE rol_name IN ($placeholders)", $allowed);
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        return false;
    }

    $roleIds = array_column($rows, 'rol_id');
    $placeIds = implode(',', array_fill(0, count($roleIds), '?'));

    $params = array_merge([$userId], $roleIds);
    $sql = "SELECT 1 FROM adm_members WHERE mem_usr_id = ? AND mem_rol_id IN ($placeIds) LIMIT 1";
    $stmt2 = $db->queryPrepared($sql, $params);
    return (bool)$stmt2->fetch();
}
