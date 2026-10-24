# Etapa 3A — Catálogo y solicitudes de compra

## Alcance y revisión previa

Se revisaron los modelos, relaciones, middleware, registro, navegación, disponibilidad y reservas, junto con `docs/etapa-02a.md`, `docs/etapa-02b.md` y `docs/roles.md`. No existe `AGENTS.md` en el proyecto revisado. Se conserva Laravel, Blade, Tailwind y Alpine.

Esta etapa implementa ofertas por profesional y solicitudes pendientes. Cada compra pertenece a la combinación **paciente + apiterapeuta vendedor**. Un pack de un profesional no es válido con otro. No se implementan acreditaciones, saldo, consumo, pagos, Mercado Pago, notificaciones externas ni carrito. Una compra no crea reservas ni ocupa horarios.

## Roles reales

No hay enum de roles en esta base. La fuente sigue siendo `User`, con constantes y métodos compartidos:

| Valor | Método | Permisos de esta etapa |
| --- | --- | --- |
| `paciente` | `isPatient()` | Ofertas disponibles, solicitudes propias, consulta y cancelación propias |
| `apiterapeuta` | `isTherapist()` | Crear, editar y activar/desactivar sus ofertas; consultar sus compras |
| `administrador` | `isAdministrator()` | Gestionar ofertas de todos los profesionales y consultar todas las compras |

`administrador` es el rol añadido expresamente para cumplir la gestión administrativa solicitada en 3A. No existía un tercer rol antes. No se asignó a ninguna cuenta, no se modificaron valores almacenados y no se cambiaron credenciales. `therapist`, `patient` y `admin` siguen siendo valores desconocidos, sin conversión automática.

`roleOptions()` reconoce los tres roles y `registrationRoleOptions()` permite solo paciente y apiterapeuta. El selector y la validación del registro usan la segunda lista, por lo que un POST manipulado no puede registrar administradores. El perfil tampoco permite elevar el rol. La administración tiene panel y navegación propios, y no obtiene permisos de paciente ni acceso a citas, agenda o configuración de horarios ajenos.

## Datos, enums y migración

Migración aditiva: `database/migrations/2026_10_26_000000_create_session_offers_and_purchases.php`.

`session_offers` guarda:

- Profesional propietario, nombre, descripción opcional.
- `SessionOfferType`: `single` (Sesión individual) o `pack` (Pack).
- Cantidad de sesiones, precio CLP entero, estado activo y timestamps.

Una individual contiene exactamente una sesión. Un pack contiene de 2 a 100 sesiones. El precio debe ser un entero positivo entre 1 y 2.147.483.647 CLP, dentro de los límites del campo y sin cálculo monetario en coma flotante. Se rechazan decimales y cadenas formateadas como `25.000`; los formularios reciben `25000` y la interfaz muestra `$25.000 CLP`.

Las ofertas no tienen duración propia. Se utiliza `config('appointments.session_minutes')`, actualmente 45 minutos, sin modificar esa configuración ni introducir tipos de atención de distinta duración.

`purchases` guarda:

- ID interno y referencia pública ULID única.
- Paciente, vendedor y oferta con claves foráneas.
- Nombre, tipo, cantidad de sesiones y precio total como copias históricas.
- Moneda fija `CLP`, estado, UUID de idempotencia por paciente, cancelación UTC opcional y timestamps.

`PurchaseStatus` contiene únicamente `pending_payment` (Pendiente de pago) y `cancelled` (Cancelada). No existe estado Pagada o Acreditada en esta implementación.

La clave única `purchase_patient_request` combina paciente e idempotencia. Hay índices de listado por paciente/profesional y fecha. La referencia pública también es única. Una clave foránea compuesta enlaza `(session_offer_id, therapist_profile_id)` con la pareja de oferta y vendedor: la base rechaza que una compra se vincule a un profesional diferente del propietario de la oferta.

No hay rutas de eliminación de ofertas. Se activan o desactivan y se conserva el vendedor original, incluso al editar como administrador. Las claves foráneas restrictivas impiden eliminar ofertas vinculadas a compras o borrar el historial por cascada. Breeze muestra un error controlado si una cuenta tiene compras o catálogo, incluso si las compras fueron canceladas. Las cuentas sin historial conservan su flujo anterior.

## Autorización, transacciones e idempotencia

`SessionOfferService` deriva el vendedor del usuario autenticado cuando actúa un apiterapeuta. Ignora identificadores de propietario manipulados. El administrador puede seleccionar un perfil profesional al crear, pero no reasignar el vendedor de una oferta existente. Lecturas y operaciones por URL se restringen al propietario o administrador.

`PurchaseService` recibe solo usuario autenticado, oferta y clave de solicitud. El paciente y vendedor nunca se toman del precio, cantidad, estado ni propietario enviados por el navegador. La creación relee los datos dentro de la transacción y exige paciente activo, vendedor activo con rol profesional, aceptación de nuevas citas y oferta activa. No es necesario escoger ni bloquear un horario para solicitar una compra.

Orden de bloqueos de compra:

1. Perfil de paciente existente.
2. Perfil del apiterapeuta existente.
3. Usuarios necesarios por ID ascendente.
4. Oferta actual y compra identificada por la clave.

Este orden respeta el de reservas de 2B. La edición de ofertas bloquea profesional → usuarios por ID → oferta, sin adquirir un perfil de paciente después. Comparte el bloqueo profesional con cambios de horarios y aceptación de citas. Las transacciones reintentan hasta tres veces los conflictos de concurrencia reconocidos por Laravel.

Las copias históricas se escriben desde la oferta bloqueada y los importes permanecen enteros. Editar o desactivar una oferta no actualiza compras anteriores. Una compra conserva su profesional vendedor y paciente para que etapas futuras puedan acreditar y consumir exclusivamente en esa combinación.

La idempotencia usa UUID normalizado a minúsculas y restricción única por paciente. La misma oferta y clave devuelven la compra original, aunque el precio cambie o la oferta sea desactivada. Otra oferta con la misma clave se rechaza. Una compra cancelada sigue cancelada al reintentar y no se reactiva. Pacientes distintos pueden usar la misma clave sin compartir compras.

Solo el paciente propietario puede cancelar una pendiente. La cancelación conserva la fila y registra `cancelled_at` en UTC. Repetirla no altera esa fecha. El profesional y administrador tienen acceso de consulta a las compras que les corresponden y no pueden cancelarlas o marcarlas como pagadas.

## Interfaz

- Profesional: sección **Sesiones y packs**, listado paginado, formularios y activación/desactivación. Acceso a solicitudes paginadas.
- Administrador: las mismas herramientas para todos los vendedores, selección de vendedor al crear y consulta global de solicitudes.
- Paciente: ofertas activas dentro de la página de cada profesional, resumen, confirmación y **Mis compras** con detalle y cancelación.
- Resumen y detalle muestran: **Estas sesiones son válidas únicamente con [profesional].**
- La confirmación queda **Pendiente de pago**, con un mensaje explícito de que aún no se han acreditado sesiones. No hay botón para pagar o aprobar pagos.
- Se conservan las horas visibles `HH:mm`, la nota **Todos los horarios corresponden a Santiago de Chile.**, America/Santiago y el almacenamiento UTC. No se modificaron timestamps ISO ni formularios de reservas.
- Se reutilizan tarjetas, botones, mensajes de validación y layouts. La navegación inferior conserva espacio seguro y el menú superior pasa a escritorio desde `lg`, evitando comprimir las secciones nuevas en tablet.

Los precios de ejemplo se usan exclusivamente como fixtures editables de las pruebas: una sesión $25.000 y pack de 10 $220.000. No se sembraron ofertas ni se sobrescribieron precios en la base del proyecto.

## Archivos principales

- Enums nuevos: `app/Enums/SessionOfferType.php`, `app/Enums/PurchaseStatus.php`.
- Modelos nuevos: `app/Models/SessionOffer.php`, `app/Models/Purchase.php`; relaciones en `PatientProfile` y `TherapistProfile`.
- Servicios nuevos: `app/Services/SessionOfferService.php`, `app/Services/PurchaseService.php`.
- Validación: `app/Http/Requests/SaveSessionOfferRequest.php`.
- Controladores nuevos: `SessionOfferController`, `PurchaseController`. Integración en `TherapistDirectoryController` y protección del historial en `ProfileController`.
- Roles: `User`, validación de `Auth/RegisteredUserController` y selector público de registro. No se modificó el esquema de roles.
- Vistas nuevas: `resources/views/offers/{index,form}.blade.php`, `resources/views/purchases/{review,index,show}.blade.php`, componentes `clp` y `session-offer-card`.
- Vistas integradas: perfil del profesional/disponibilidad, dashboard, navegación, layout y mensajes de eliminación de cuenta.
- Rutas en `routes/web.php`; acceso a detalles de citas limitado explícitamente a paciente y apiterapeuta para no ampliar permisos al administrador.
- Pruebas nuevas: `tests/Feature/SessionCatalogPurchaseTest.php`. `RoleConsistencyTest` conserva sus comprobaciones y actualiza los valores canónicos esperados a los tres roles, manteniendo dos opciones de registro público.
- Documentación: este archivo y actualización de `docs/roles.md`.

## Rutas nuevas

Todas requieren autenticación y cuenta activa.

| Método | Ruta | Nombre | Autorización |
| --- | --- | --- | --- |
| GET | `/catalogo` | `offers.index` | Profesional propio / administrador global |
| GET | `/catalogo/crear` | `offers.create` | Profesional / administrador |
| POST | `/catalogo` | `offers.store` | Profesional / administrador |
| GET | `/catalogo/{offer}/editar` | `offers.edit` | Propietario / administrador |
| PUT | `/catalogo/{offer}` | `offers.update` | Propietario / administrador |
| PATCH | `/catalogo/{offer}/estado` | `offers.toggle` | Propietario / administrador |
| GET | `/ofertas/{offer}/solicitar` | `purchases.review` | Paciente, solo oferta disponible |
| POST | `/compras` | `purchases.store` | Paciente para sí mismo |
| GET | `/compras` | `purchases.index` | Propias / vendedor / administrador global |
| GET | `/compras/{purchase}` | `purchases.show` | Propietario / vendedor / administrador |
| POST | `/compras/{purchase}/cancelar` | `purchases.cancel` | Solo paciente propietario |

`{purchase}` es la referencia pública, no el ID interno. No hay DELETE de ofertas ni rutas de aprobación, pago, crédito o consumo. Citas, horarios, bloqueos y servicios de reservas conservan su lógica e idempotencia.

## Comprobaciones reales

- **Laravel Pint:** aplicado a todos los archivos PHP de esta etapa; comprobación posterior correcta.
- **`php artisan test`: 110 pruebas aprobadas, 801 aserciones.** Incluye las anteriores y 16 pruebas nuevas de catálogo/compras.
- Pruebas funcionales sobre SQLite `:memory:` forzado por `phpunit.xml`, nunca sobre la base de cuentas. Se habilitaron las extensiones SQLite ya instaladas mediante el directorio INI temporal documentado en 2A.
- Se activaron también las dos pruebas existentes de concurrencia real de **reservas** en `beehappy_concurrency_test`, con conexiones independientes; siguen pasando. No se presentan esas pruebas como una prueba de concurrencia simultánea de compras: las pruebas nuevas de compras verifican idempotencia funcional y las restricciones de integridad.
- **`npm run build`: correcto**, 59 módulos compilados por Vite.
- **`php artisan view:cache`: correcto**. Las pruebas HTTP renderizan formularios, catálogo público/privado, resumen, listas, detalle y dashboard administrativo.
- **`php artisan route:list`: correcto**, 49 rutas totales y 11 rutas nuevas.
- **`git diff --check`: correcto**.
- **Migración aplicada:** antes de ejecutarla se verificaron driver MySQL, host local y base efectiva `beehappy` mediante una consulta de lectura. La nueva migración quedó aplicada en lote 5, confirmado por `migrate:status`.
- No se ejecutó `migrate:fresh` sobre la base del proyecto, no se borraron datos ni se cambiaron credenciales, asignaciones de roles, ofertas o precios existentes. No hubo commit ni push ni instalación de dependencias.

Cobertura nueva: propiedad por URL y payload, administración global, registro sin elevación de privilegios, límites de tipo/cantidad/precio, oferta y profesional no disponibles, paciente inactivo pese a un modelo autenticado anterior, precio/cantidad/identidades determinados por el servidor, copias históricas, clave por paciente, reintento cancelado, acceso a compras ajenas, cancelación, paginación, restricción compuesta de vendedor, conservación de cuentas y ausencia de efectos sobre citas/disponibilidad o créditos.

Para repetir la suite, con las extensiones temporales ya preparadas:

```powershell
$env:PHP_INI_SCAN_DIR = Join-Path $env:TEMP 'beehappy-stage02a-check'
$env:BEEHAPPY_RUN_MYSQL_CONCURRENCY = '1'
php artisan test
```

Sin la bandera de concurrencia, las dos pruebas MySQL existentes se omiten explícitamente; las pruebas de catálogo y compras continúan usando SQLite en memoria. Si se desea ejecutar solo las nuevas:

```powershell
php artisan test --filter=SessionCatalogPurchaseTest
```

## Pruebas manuales

1. Entra como apiterapeuta activo. Desde **Sesiones** o **Gestionar mis ofertas**, crea una individual con cantidad 1 y precio `25000`. Crea un pack con cantidad 10 y precio `220000`. Son ejemplos: se pueden elegir otros precios válidos.
2. Intenta guardar una individual con 2 sesiones, un pack con 1 o 101, precio cero o decimal: debe mostrarse el error y no guardarse la oferta inválida.
3. Edita una oferta y desactívala desde el listado. Entra con otro profesional y comprueba que no puede verla en su gestión ni modificarla por URL.
4. Entra como paciente y abre el perfil del vendedor desde **Apiterapeutas**. Solo deben aparecer ofertas activas de profesionales activos que aceptan citas. La duración sigue siendo la configurada para todas las sesiones.
5. Pulsa **Revisar solicitud**. Comprueba profesional, cantidad, importe CLP y la restricción de uso exclusivo con ese vendedor. Todavía no debe existir una compra nueva ni una reserva.
6. Confirma. Debe aparecer el detalle **Pendiente de pago**, la referencia y el mensaje de que aún no se han acreditado sesiones. Comprueba la solicitud en **Mis compras** y en las solicitudes del vendedor.
7. Cambia después nombre, tipo, cantidad y precio de la oferta usando valores válidos. La compra anterior debe conservar sus condiciones históricas.
8. Repite el POST de compra con la misma oferta y UUID: debe volver al mismo detalle. Otra oferta con esa clave debe rechazarse. Otro paciente no puede consultar ni cancelar la referencia ajena.
9. Cancela desde el paciente propietario. La fila debe permanecer **Cancelada**. Repetir la cancelación conserva la fecha, y repetir la solicitud original no la reactiva. El profesional y administrador no tienen acción de cancelación ni aprobación.
10. Con una cuenta **ya autorizada** como `administrador`, comprueba gestión de ofertas de varios vendedores y consulta global de compras. El registro público y el perfil nunca ofrecen asignar ese rol. Esta etapa no crea ni modifica cuentas para ese fin.
11. Comprueba que pedir un pack no modifique la disponibilidad ni cree citas. Las reservas existentes deben seguir funcionando independientemente de las compras pendientes.
12. Con más de 12 filas, revisa la paginación. Revisa también escritorio y 390 px: campos, importes, descripciones largas, botones, navegación y espacio inferior.

## Pendientes

- **Revisión visual en navegador:** pendiente en escritorio y 390 px. No se ejecutó una inspección visual del resultado; no hay herramienta de navegador habilitada y el intento local previo de lanzar Edge había sido rechazado por permisos. Sí se verificaron compilación Blade, build y renderizado HTTP.
- Si no existe una cuenta administradora previamente autorizada, su aprovisionamiento queda fuera de esta etapa: no se cambian las asignaciones de las cuentas ni se habilita registro público administrativo.
- Etapas posteriores: pagos verificados, acreditación de sesiones y consumo, siempre por paciente + profesional. No se implementaron saldos, aprobaciones de pago, consumo ni Mercado Pago. La implementación se detiene en 3A.
