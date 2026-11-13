# Pruebas manuales de RifaCloud

Este directorio contiene el entorno de pruebas funcionales sobre
`rifacloud_test`. La base principal `rifacloud` no se modifica.

| Documento | Uso |
|---|---|
| [`TEST_USERS.md`](TEST_USERS.md) | Usuarios y credenciales locales |
| [`TEST_DATA.md`](TEST_DATA.md) | Rifas, órdenes y números preparados |
| [`MANUAL_TEST_PLAN.md`](MANUAL_TEST_PLAN.md) | Recorridos para probar en el navegador |
| [`MERCADOPAGO_SANDBOX.md`](MERCADOPAGO_SANDBOX.md) | Sandbox real con túnel HTTPS |

## Inicio rápido

```powershell
# Primer inicio: construye imágenes, migra rifacloud_test y siembra los datos
powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1

# Siguientes inicios, si las imágenes ya están construidas
powershell -ExecutionPolicy Bypass -File scripts/prepare-demo-environment.ps1 -SkipBuild
```

La aplicación queda en <http://localhost> con el gateway simulado y reservas de
60 minutos para facilitar las pruebas manuales.

## Credenciales e IDs

Se generan localmente y están ignorados por Git:

```powershell
Get-Content docs/testing/.local/test-users.json
Get-Content docs/testing/.local/test-data.json
```

No copies esos archivos a Git, correos, capturas o documentación.

## Verificación rápida y limpieza

```powershell
powershell -ExecutionPolicy Bypass -File scripts/verify-demo-environment.ps1
powershell -ExecutionPolicy Bypass -File scripts/cleanup-demo-environment.ps1
```

`verify-demo-environment.ps1` no escribe datos: inicia sesión con los tres
usuarios, comprueba catálogo, historial, aislamiento y métricas del dashboard.

`cleanup-demo-environment.ps1` elimina las rifas `[DEMO]`, las órdenes
`DEMO-` y las sesiones demo de `rifacloud_test`, y restaura el stack normal
sobre `rifacloud`. Usa `-PurgeUsers` si además quieres eliminar los usuarios
demo que ya no tengan órdenes.
