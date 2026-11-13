# Usuarios de prueba

## Cuentas

| Rol | Nombre visible | Correo | Uso |
|---|---|---|---|
| ADMIN | Demo administrador | `demo-admin@rifacloud.test` | Dashboard, rifas, publicación de resultados, incidencias |
| USER | Demo comprador uno | `demo-buyer1@rifacloud.test` | Compras, reservas, historial e incidencias propias |
| USER | Demo comprador dos | `demo-buyer2@rifacloud.test` | Aislamiento: no debe ver las órdenes del comprador uno |

Las contraseñas son aleatorias, exclusivas de este entorno local y no se
guardan en Git.

## Cómo obtener las credenciales

```powershell
Get-Content docs/testing/.local/test-users.json
```

El archivo lo genera `scripts/prepare-demo-environment.ps1` la primera vez.
En ejecuciones posteriores reutiliza las mismas contraseñas para no invalidar
las sesiones de prueba.

Ubicación local:

```text
docs/testing/.local/test-users.json
```

`docs/testing/.local/` está en `.gitignore`. No publiques ni adjuntes ese
archivo. No uses estos correos o contraseñas fuera de `rifacloud_test`.

## Regenerar credenciales

Si necesitas contraseñas nuevas:

```powershell
Remove-Item docs/testing/.local/test-users.json -Force
powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1 -SkipBuild
```

El seeder actualiza los hashes de los tres usuarios y recrea los datos demo.
Esto invalida las sesiones existentes de esas cuentas.

## Cuentas equivalentes en `rifacloud` (entorno de desarrollo)

Cuando el stack normal corre contra `rifacloud` (el caso por defecto de
`docker compose up`), los correos anteriores no existen: los fixtures demo
solo se siembran en `rifacloud_test`. Para poder iniciar sesión sin cambiar de
stack se crearon manualmente las mismas tres cuentas en `rifacloud` con las
contraseñas de `docs/testing/.local/test-users.json`:

| Rol | Correo | IDs locales |
|---|---|---|
| ADMIN | `demo-admin@rifacloud.test` | 41 |
| USER | `demo-buyer1@rifacloud.test` | 42 |
| USER | `demo-buyer2@rifacloud.test` | 43 |

Notas:

- Solo existen en la base local de desarrollo; no forman parte de las
  migraciones ni de los seeders versionados.
- `scripts/cleanup-demo-environment.ps1` **no** las elimina, porque solo opera
  sobre `rifacloud_test`.
- Si se recrea el volumen de MySQL o se purga `rifacloud`, hay que volver a
  crearlas (no lo hace ningún script automático).

## Inicio de sesión

1. Abre <http://localhost/login>.
2. Introduce el correo y la contraseña del archivo local.
3. El administrador entra en <http://localhost/admin/dashboard>.
4. Los compradores entran en <http://localhost/orders>.

No existe un administrador por defecto fuera de estos fixtures. Las cuentas
demo solo existen en `rifacloud_test` mientras no se ejecute la limpieza con
`-PurgeUsers`. El archivo local de credenciales no se borra automáticamente:
vuelve a ejecutar `prepare-demo-environment.ps1` para recrear los usuarios con
esas mismas contraseñas o elimina el archivo para generar otras.
