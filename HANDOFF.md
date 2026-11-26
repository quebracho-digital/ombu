# Handoff: Ombú, 2026-11-26

Para arrancar una sesión nueva con contexto limpio. Leer en este orden: este archivo, `DECISIONS.md` (sección 2026-11-25, de arriba abajo), `README.md` y `deploy/RESTORE.md`. Memoria de la sesión anterior: `~/.claude/projects/-home-leo-Proyects-ombu/memory/` (`deploy-only-git-pull`, `ser5-deploy-gotchas`, `a2ui-spike`, `ddev-test-and-paid-urls`).

## Reglas de Leo que no se negocian
- **El deploy a SER5 es commit, push, `git pull`, y solo después de que Leo diga "deploya"** (`scripts/deploy-ser5.sh`). Nunca scp, rsync, `docker cp` ni editar a mano en SER5 para llevar algo de Ombú (el hook `.claude/hooks/ser5-guard.sh` lo bloquea; scp y rsync para otras tareas siguen libres). El 25/11 lo rompí con el bloqueo de Apache y Leo se enojó: no repetirlo, ni siquiera por urgencia.
- Commits locales sin pedir permiso, con rutas explícitas (nunca `git add -A`); push y tags solo con su OK.
- Si Leo decide algo: registrarlo en `DECISIONS.md` en el mismo turno, citando sus palabras, y una línea en `~/Proyects/Quebracho/docs/inbox.md`.

## Estado de producción (SER5, ombu.quebracho.digital)
- Corre `f425492`, desplegado el 25/11: A2UI y el servicio `sellercheck` (0.005 USDC en Arc mainnet, a la wallet fría de Leo), importadores en Feeds (instalados, **sin programar**: siguen los importadores viejos), Drupal core 11.4.14 (SA-CORE-2026-019), post del deploy en tres idiomas con vista previa A2UI.
- Backup previo: `/data/docker/ombu/pre-f425492-20261125-1723.*`. Dos compras reales de verificación hechas (tx `0x80bb7399…12dba`, `0x174c9308…a51b`).
- **Revisión de compromiso por el instalador (artículo de drupal.org): limpia.** `install_time` 2026-10-09 (fecha real), sin token de sesión, usuario 1 intacto, sin PHP nuevo en `files`, logs sin POST al instalador. Falta solo el comentario `comment-16854042` de esa página, que Leo no ve (necesita privilegios); si lo pega, revisarlo.
- **`/core/install.php` da 403 solo porque lo copié al contenedor `ombu-web-1` a mano** (no por deploy). Se pierde si recrean el contenedor. El commit con `deploy/apache-block-install.conf` y la línea del `Dockerfile` ya está en `main`; el próximo deploy lo trae y reconstruye la imagen (el script lo detecta). Leo: "con el proximo deploy que hagamos, ya le incluimos el commit del bloqueo".

## Pendiente de entrar en prod (en `origin/main`, falta deployar)
- El bloqueo del instalador (arriba).
- `config/sync/update.settings.yml`: sin email de avisos de actualización (era el placeholder `admin@example.com`; Leo no quiere un mail al día).
- `scripts/deploy-ser5.sh` y el hook (solo repo, no cambian prod).
- **Los feeds del catálogo, con los arreglos del review y de la auditoría de seguridad** (2026-11-26): guarda de retiro que ya no es trinquete, una entrada duplicada no retira el listado de otro, Circle no pisa el término del proveedor de otro vendedor. Siguen sin programarse: los importadores viejos corren en cron.
- **Mercado: facetas de origen y de transacciones** (`ombu_update_10040`/`10041` instalan `field_activity` y completan lo que ya existía). Después del deploy hay que **reindexar los dos índices** (`drush search-api:index` o el cron) o la faceta Transactions muestra solo lo nuevo.
- **El bloque de compartir con las marcas de cada red** (2026-11-26): solo plantilla, CSS y JS del módulo; ningún cambio de config.
- **El script de deploy nunca corrió entero contra SER5**: el primer "deploya" es su primera ejecución real. Mirar la salida de cada paso; el mantenimiento se activa solo justo antes del `git pull`; usa `drush deploy` (updb, cim, cr), no cim antes que updb.

## Abierto
- Pasar los importadores del catálogo a Feeds (programar los 3 feed types y quitar el cron viejo): decisión de Leo, no tomada.
- Revisar los listados importados que quedaron `activity = unknown` cuando el índice no pudo decir si hubo transferencias: se completan en la próxima corrida que los verifique (`--min-transfers` filtra desde el import).
- `quebracho/x402` 0.6.0 está bloqueado por `x402_pay` beta5 (`^0.5.1`): hace falta una versión de x402_pay que lo amplíe. La lista OFAC la refresca el cron de x402_pay, no la librería.
- Auditoría, no atendido: `/ombu/a2ui/render` sin límite de tasa; el formulario A2UI envía claves del modelo de datos que no usa; portabilidad MySQL a Postgres sin probar (los Kernel tests deben correr en MySQL, no SQLite).
- El post en pt-br necesita revisión de un hablante nativo.
- **Multimoneda en x402_pay (decidido 2026-11-26, sin empezar)**: varios activos aceptados por red (hoy USDC fijo en `Networks.php` y `ModuleNetworks.php`), EURC en Arc primero. Primera versión: precio y pago en la misma moneda (USD o EUR), sin tipo de cambio. El vendedor marca qué activos acepta; el 402 ofrece un `accepts` por activo. Verificar antes que el EURC de Arc soporte EIP-3009 (`transferWithAuthorization`) y anotar su `name`/`version` EIP-712. Tocan: catálogo de redes, formulario de ajustes, campo de precio y formateador, sweeper (supone que el gas es el USDC que barre) y la verificación del monto. Cambia la API pública: va en una beta nueva de x402_pay, con OK de Leo para el release. Segunda etapa (precio ARS/USD, pago en otro activo): tipo de cambio con antigüedad, compartido con la tarea 4 de hotel-quebracho. Origen: issue de Peach (cotización de swap EURC→USDC), que no se integra; se responde que es un enlace externo opcional si hay demanda.
- Hoja de ruta: vendedores de terceros con A2UI, `extensions.a2ui` en x402, binding MCP, otros catálogos, prueba de clic real con billetera en el navegador.

## Notas técnicas que ahorran tiempo
- Tests: `ddev exec` con `SIMPLETEST_DB=mysql://db:db@db/db`; `ddev test` no entrecomilla `$@`; si falta `web/core/phpunit.xml`, `cp -n web/core/phpunit.xml.dist web/core/phpunit.xml`. Suite completa: 487 tests, unos 40 minutos.
- Config creada por un update debe llevar el mismo `uuid:` que `config/sync` (si no, `cim` borra y recrea); lo cubre `DeployConfigTest`.
- SSH a SER5: `ssh quebracho@192.168.1.66`. Host de prod: `ombu.quebracho.digital` (con `quebracho`, no `quebrachos`).
- Compra real: `scripts/buy.php` con `scripts/.env.mainnet` dentro de `ddev exec bash` (heredoc para el JSON). Gasta dinero real: solo cuando Leo lo pida.
