# Empresas y secciones — plugin para Admidio 5.x

Gestión de empresas, secciones sindicales y afiliación por empresa dentro de Admidio.

## Qué hace

- Listado de empresas con búsqueda, filtros (sector, sección, portavoz) y paginación.
- Ficha de empresa: datos, dirección, sección sindical (nombre, correo, portavoz) y miembros asignados con su cargo.
- Para cada miembro muestra sus roles vigentes y, si existe el plugin SEPA, su forma de pago y el total a percibir por nómina.
- Una empresa solo se puede borrar si no tiene miembros asignados.

## Qué NO hace

- **No modifica ningún fichero de Admidio** (núcleo, plantillas ni temas). Sobrevive a las actualizaciones.
- **No crea campos de perfil.** La relación persona–empresa vive en las tablas del plugin.

## Requisitos

- Admidio 5.x con MySQL/MariaDB
- PHP 8.1+

## Instalación

1. Copia la carpeta del plugin a `adm_plugins/companies_and_sections/`.
   El nombre de la carpeta tiene que ser exactamente `companies_and_sections`.
2. Entra en Admidio como administrador y abre:
   ```
   https://tu-servidor/adm_plugins/companies_and_sections/install.php
   ```
3. Pulsa **Instalar**. El instalador:
   - crea las tablas `crm_organizations` y `crm_member_organizations`,
   - añade la entrada **Empresas y secciones** al menú de Administración,
   - crea `config.php` (solo administradores) si no existe,
   - intenta borrarse. Si no puede, **borra `install.php` a mano**.

Se puede ejecutar varias veces: solo crea lo que falte.

### Docker

Monta la carpeta como volumen para que no se pierda al recrear el contenedor:

```yaml
volumes:
  - ./plugins/companies_and_sections:/opt/app-root/src/adm_plugins/companies_and_sections
```

(ajusta la ruta de la derecha a donde esté Admidio dentro de tu imagen).

## Permisos

Edita `config.php` (ver `config.example.php`):

```php
<?php
return [
    'allowed_roles' => [
        'Secretario/a de Organizacion',
        'Secretario/a de Tesorería',
    ],
];
```

- Los administradores siempre tienen acceso.
- El resto necesita pertenecer **ahora** a uno de esos roles (una pertenencia terminada ya no da acceso).
- Lista vacía = solo administradores.
- La entrada de menú es visible para todos por defecto; restríngela en Menú → editar entrada.
  Las páginas comprueban los permisos igualmente.

El plugin muestra datos sensibles (roles, forma de pago). Da acceso solo a quien lo necesite.

## Seguridad

- Todas las acciones (crear, editar, borrar, asignar, quitar) van por POST con token CSRF de Admidio.
- Usa la conexión de base de datos de Admidio (`$gDb`); no guarda credenciales.
- Toda la salida se escapa; las entradas se validan y se recortan al tamaño de las columnas.
- Los errores de base de datos no se muestran al usuario (se registran en el log).

## Ficheros

```
companies_and_sections/
├── organization_functions.php  # arranque, permisos, CSRF, utilidades
├── organizations.php           # listado
├── create_organization.php     # alta de empresa
├── organization_profile.php    # ficha, edición y miembros
├── get_organizations.php       # JSON con las empresas activas (usuarios autorizados)
├── install.php                 # instalador (bórralo tras instalar)
├── config.example.php
└── README.md
```

## Desinstalar

1. Borra la entrada de menú en Menú.
2. Si quieres borrar también los datos:
   ```sql
   DROP TABLE crm_member_organizations;
   DROP TABLE crm_organizations;
   ```
3. Borra la carpeta del plugin.

## Licencia

Ver [LICENSE](LICENSE).
