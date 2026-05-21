<?php
/**
 * Companies and Sections Plugin - JavaScript Generator
 *
 * Generate JavaScript code to inject in Admidio profiles.
 * Add a link "See Company" when Company field is present.
 *
 * @package CompaniesAndSections
 */

require_once(__DIR__ . '/../../system/common.php');

// Protection: If user is not authorized, we don't render the script
require_once(__DIR__ . '/organization_functions.php');
if (!isUserAuthorized($gDb)) {
    header('Content-Type: application/javascript; charset=UTF-8');
    echo '/* Companies and Sections: script oculto por permisos */';
    exit;
}

// Headers for caching and JavaScript
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

/**
 * Obtain the ID of the profile field "Company".
 *
 * @param string $fieldNameIntern Internal name of the field
 * @return int|null ID of the field or null if it doesn't exist
 */
function getFieldId(string $fieldNameIntern): ?int
{
    global $gDb;
    if ($fieldNameIntern === '') return null;

    $stmt = $gDb->queryPrepared(
        'SELECT usf_id FROM ' . TBL_USER_FIELDS . ' WHERE usf_name_intern = ?',
        [$fieldNameIntern]
    );
    $row = $stmt->fetch();
    return $row ? (int)$row['usf_id'] : null;
}

// Obtain the ID of the CRM_EMPRESA field
$empresaFieldId = getFieldId('CRM_EMPRESA');

// If the field doesn't exist, exit silently
if ($empresaFieldId === null) {
    echo '/* Companies and Sections: Campo CRM_EMPRESA no encontrado */';
    exit();
}

// Obtain the base URL of Admidio
$rootUrl = rtrim($gRootPath ?? 'http://localhost:8080', '/');
?>
/**
 * Companies and Sections - Profile Enhancement Script
 *
 * This script adds a link "See Company" next to the Company field
 * in Admidio profile forms.
 *
 * The script searches for the company field by its ID and, when the user
 * selects a company, displays a link to view the details.
 * @version 1.0.0
 */
(function () {
    'use strict';

    var EMPRESA_FIELD_ID = <?= $empresaFieldId ?>;
    var ROOT_URL = '<?= $rootUrl ?>';
    var CRM_PROFILE_URL = ROOT_URL + '/adm_plugins/organization_members/organization_profile.php';

    function updateEmpresaLinks() {
        var select = document.getElementById('usf_' + EMPRESA_FIELD_ID + '_field');
        if (!select) return;

        var selectedText = select.options[select.selectedIndex].text;
        var container = document.getElementById('usf_' + EMPRESA_FIELD_ID + '_group');
        if (!container) return;

        var existingLink = container.querySelector('.crm-empresa-link');
        if (existingLink) {
            existingLink.remove();
        }

        if (selectedText && selectedText !== '' && selectedText.indexOf('--') === -1) {
            var link = document.createElement('a');
            link.className = 'crm-empresa-link ms-2';
            link.href = CRM_PROFILE_URL + '?search=' + encodeURIComponent(selectedText);
            link.target = '_blank';
            link.innerHTML = '<i class="bi bi-building"></i> Ver empresa';
            link.style.color = '#DA291C';
            link.style.fontWeight = '600';
            link.style.textDecoration = 'none';

            var descContainer = container.querySelector('.form-text, .invalid-feedback');
            if (descContainer) {
                descContainer.parentNode.insertBefore(link, descContainer.nextSibling);
            } else {
                container.appendChild(link);
            }
        }
    }

    /**
     * Initializes the script when the DOM is ready.
     */
    function init() {
        var select = document.getElementById('usf_' + EMPRESA_FIELD_ID + '_field');
        if (!select) return;

        updateEmpresaLinks();
        select.addEventListener('change', updateEmpresaLinks);
    }

    // Execute when the DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
