# Organization Members — Admidio Plugin

A plugin for [Admidio](https://www.admidio.org/) 5.x that provides organization/company management and member assignment features.

## Features

- Manage organizations/companies with contact, legal, and union section details
- Create new company and assign members from Admidio users
- View organization profiles with active members and member roles
- Delete company only when no members are assigned
- Automatically synchronize company name changes to user contact data
- Access control by administrators or configured Admidio roles

## Requirements

- Admidio 5.x
- PHP 7.4+ with PDO MySQL support
- MySQL/MariaDB database used by Admidio
- Access to Admidio administrator account for installation

## Installation

1. Copy the `companiesAndSections` folder into `adm_plugins/companies_and_sections/` inside your Admidio installation.
2. Log in to Admidio as an administrator.
3. Open the installer in your browser:
   ```
   https://your-server/adm_plugins/companies_and_sections/install.php
   ```
4. Follow the installer steps. The installer will:
   - Create `crm_organizations` and `crm_member_organizations` tables
   - Create the `CRM_EMPRESA` user profile field and add it to contacts
   - Inject menu and template modifications for organization links
   - Create a default `config.php` if one does not exist
5. After a successful install, `install.php` is removed automatically.

## Configuration

Copy `config.example.php` to `config.php` and edit the `allowed_roles` array.

- If `allowed_roles` is empty, only Admidio administrators can access the plugin.
- If role names are provided, users with any of those roles can use the plugin.

Example `config.php`:

```php
<?php
return [
    'allowed_roles' => [
        'Organization Manager',
        'HR',
    ],
];
```

## Access Control

The plugin checks user permissions before allowing access:

- Administrators always have access.
- Non-administrator users must belong to at least one role listed in `config.php`.

## Plugin Pages

- `organizations.php` — Main organization list, filters, pagination, and statistics
- `create_organization.php` — Create a new organization/company
- `organization_profile.php` — Edit organization details and review assigned members
- `organization_functions.php` — Shared helper functions, including contact synchronization
- `profile_js.php` — JavaScript helper injected into Admidio pages

## Behavior

- Organizations are stored in `crm_organizations`.
- Member assignments are stored in `crm_member_organizations`.
- When an organization is renamed, `syncOrgNameInContacts()` updates the `CRM_EMPRESA` field in user contact data.
- Deleting an organization is blocked if it still has active member assignments.

## Tips

- Use role names exactly as they appear in Admidio.
- Verify that `config.php` is writable during installation.
- Keep `install.php` removed after installation for security.

## File Structure

```
organization_members/
├── config.example.php
├── create_organization.php
├── install.php
├── organization_functions.php
├── organization_profile.php
├── organizations.php
├── profile_js.php
├── README.md
```

## License

See the repository license for details.

See [LICENSE](LICENSE).
