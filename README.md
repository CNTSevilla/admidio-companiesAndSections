# Empresas y secciones — plugin para Admidio 5.x

Gestión de empresas, secciones sindicales y afiliación por empresa dentro de Admidio.

## Qué hace

- Listado de empresas con búsqueda, filtros (sector, con/sin sección sindical, delegado/a) y paginación.
- Ficha de empresa: datos, sede, sección sindical (nombre, correo, delegados/as) y miembros asignados.
- **Centros de trabajo** por empresa (nombre, dirección, teléfono, notas). Cada miembro puede
  asignarse a un centro y la lista de miembros se puede filtrar por centro.
- En cada asignación: cargo y marca de **delegado/a** (portavoz y delegado/a son lo mismo).
- **Una empresa puede existir sin sección sindical**: alguien declara dónde trabaja aunque allí no
  haya sección. Esas empresas no pueden tener delegados/as: la casilla no aparece, el servidor la
  ignora y una marca antigua no da acceso. Al quitar la sección a una empresa, sus delegados/as
  pierden el cargo (la afiliación a la empresa y al centro se mantiene).
- Para la secretaría, cada miembro muestra sus roles vigentes y, si existe el campo de perfil
  `SEPA_PAYMENT_METHOD`, su forma de pago: **Nómina** o **Domiciliación** (sin importes).
- Una empresa solo se puede borrar si no tiene miembros. Al borrar un centro, sus miembros siguen en
  la empresa sin centro.

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
   - crea las tablas `crm_organizations`, `crm_workplaces` y `crm_member_organizations`,
   - añade la entrada **Empresas y secciones** al menú de Administración,
   - crea `config.php` (solo administradores) si no existe,
   - intenta borrarse. Si no puede, **borra `install.php` a mano**.

Se puede ejecutar varias veces: solo crea lo que falte.

### Actualizar desde una versión anterior

Copia los ficheros nuevos (incluido `install.php`) y vuelve a ejecutar el instalador: añade la tabla de
centros de trabajo y las columnas nuevas sin tocar los datos existentes. Si vienes de una versión con
*portavoz*, cada portavoz pasa a ser delegado/a de su empresa (asignándolo si no lo estaba) y se quita la
marca de delegado/a en las empresas sin sección. Mientras no se actualice, el
plugin avisa a los administradores y no deja entrar a los delegados.

### Docker

Monta la carpeta como volumen para que no se pierda al recrear el contenedor:

```yaml
volumes:
  - ./plugins/companies_and_sections:/opt/app-root/src/adm_plugins/companies_and_sections
```

(ajusta la ruta de la derecha a donde esté Admidio dentro de tu imagen).

## Permisos

| Quién | Ve | Edita |
|---|---|---|
| Administradores | Todas las empresas | Todo |
| Secretaría: roles de `allowed_roles` en `config.php` | Todas las empresas, con roles y forma de pago de cada miembro | Todo |
| Delegados/as: marcados como delegado/a en una asignación de una empresa **con sección sindical** | Solo sus empresas, sin roles ni datos de pago | Nada |
| Resto | Nada | Nada |

`config.php` (ver `config.example.php`):

```php
<?php
return [
    'allowed_roles' => [
        'Secretario/a de Organizacion',
        'Secretario/a de Tesorería',
    ],
];
```

- Solo cuentan las pertenencias **vigentes** a esos roles.
- Lista vacía = solo administradores (más los delegados, en modo lectura).
- La entrada de menú es visible para todos por defecto; restríngela en Menú → editar entrada.
  Las páginas comprueban los permisos igualmente.

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
├── organization_profile.php    # ficha, centros de trabajo y miembros
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
   DROP TABLE crm_workplaces;
   DROP TABLE crm_organizations;
   ```
3. Borra la carpeta del plugin.

## Licencia

Ver [LICENSE](LICENSE).
