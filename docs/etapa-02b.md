# Etapa 2B — Reservas, historial y agenda

## Base revisada y alcance

Se leyeron `docs/etapa-02a.md` y `docs/roles.md`, y se revisaron modelos, autorización, rutas, horarios y bloqueos. No existe `AGENTS.md` en el proyecto revisado. Se mantienen Laravel, Blade, Tailwind y Alpine, la identidad BeeHappy y los componentes existentes.

Se implementan reserva con resumen y confirmación, cancelación, historial del paciente, próxima cita real y agenda diaria del apiterapeuta. No se implementan packs, pagos ni notificaciones externas. **Agendada no significa pago aprobado.**

## Roles y estados reales

No existe un enum de roles en esta base. La fuente existente es `User`, con `ROLE_PATIENT = paciente` y `ROLE_THERAPIST = apiterapeuta`. Se reutilizan `isPatient()`, `isTherapist()`, `hasKnownRole()` y el middleware `role`. `therapist` es un nombre técnico, no un alias de rol. Los roles desconocidos siguen rechazándose. No se cambiaron roles almacenados.

Los estados sí se centralizan en el nuevo enum `App\Enums\AppointmentStatus`:

| Valor persistido | Estado visible |
| --- | --- |
| `scheduled` | Agendada |
| `cancelled` | Cancelada |
| `completed` | Realizada |
| `no_show` | No asistió |

Transiciones permitidas:

- Agendada → Cancelada: solo el paciente o profesional propietarios, antes del comienzo.
- Agendada → Realizada o No asistió: solo el profesional propietario, cuando la sesión ya terminó.
- Cancelada → Cancelada: repetición sin cambios de autor, fecha ni motivo.
- No hay reactivación ni cambios entre estados terminales. No se acepta un estado genérico enviado desde el navegador.

## Migración y conservación del historial

Migración aditiva: `database/migrations/2026_10_25_000000_create_appointments.php`.

- `patient_profiles`: usuario único, clave foránea y timestamps. Se crean perfiles para pacientes existentes sin modificar sus cuentas; el registro crea los nuevos mediante la relación de `User`. Una cuenta de fixtures sin eventos puede inicializar su perfil antes de su primera reserva, gracias al índice único de usuario.
- `appointments`: paciente, apiterapeuta, inicio y término UTC, estado, duración y separación originales, UUID de idempotencia, autor/fecha/motivo de cancelación y timestamps.
- `busy_until` guarda en UTC el término de la sesión más la separación profesional original. Permite consultar conflictos con un índice, sin reinterpretar reservas cuando cambia la configuración.
- Índice único `appointment_patient_request` sobre paciente y clave. Índices de intervalos por paciente y profesional.
- Las referencias a perfiles usan `RESTRICT ON DELETE`: el borrado en cascada de un usuario no puede eliminar citas. `cancelled_by` es nullable con `SET NULL` para preservar la fila incluso si se eliminara un autor sin vínculo de propietario.

La eliminación de cuentas de Breeze bloquea perfiles en el mismo orden de las reservas y rechaza, con un mensaje visible, cuentas con cualquier historial de citas, incluidas las canceladas. Mantiene la sesión del usuario cuando se rechaza. Las cuentas sin citas conservan el flujo de eliminación anterior. El logout ocurre después de comprobar el historial y antes del borrado, para evitar que la rotación del token de recuerdo vuelva a guardar un modelo ya eliminado.

## Creación, disponibilidad e idempotencia

`AppointmentService` determina el paciente desde el usuario autenticado. El cliente solo selecciona profesional, fecha y un instante ofrecido por `AvailabilityService`; también envía la clave de solicitud generada en el resumen. Duración, separación, término, ocupación profesional y estado inicial se calculan en el servidor.

Los instantes recibidos deben ser ISO 8601 UTC a precisión de minuto (`+00:00` o `Z`), con calendario real y coincidencia con la fecha local seleccionada. Después de adquirir los bloqueos se vuelven a verificar usuarios activos, rol, aceptación de citas, fecha futura, horizonte, tramos, bloqueos y conflictos. Debe coincidir con un slot realmente ofrecido. El horizonte sigue en `config/appointments.php`: 60 fechas incluyendo hoy, hasta hoy + 59 días; la creación exige además un inicio estrictamente futuro.

La consulta de disponibilidad descuenta las citas no canceladas del profesional y las citas del paciente autenticado con cualquier profesional. Solo devuelve inicio, término y etiqueta del slot, sin revelar quién ocupa las otras horas.

Intervalos semiabiertos:

- Profesional: `[starts_at, busy_until)`, usando la separación que quedó guardada en cada cita. La separación de la nueva candidata también se considera antes de una cita posterior.
- Paciente: `[starts_at, ends_at)`, sin añadir la separación del profesional.
- Las canceladas liberan ocupación; Agendada, Realizada y No asistió la conservan.
- Los bloqueos se comprueban contra toda la ocupación del profesional, incluida su separación. Los tramos semanales deben cubrir la sesión completa dentro de un tramo; el descanso posterior puede quedar fuera del tramo, como en la generación de 2A.

Las reservas conservan sus campos originales aunque se cambien duración o separación en la configuración. Al editar horarios se comprueba la cobertura de la duración real de la cita, sin exigir que vuelva a coincidir con la cuadrícula de slots de la configuración actual.

La idempotencia se aplica por paciente y UUID, normalizado a minúsculas. Una repetición con el mismo profesional e instante devuelve la misma fila antes de comprobar de nuevo el horizonte o la configuración: no crea otra cita ni cambia su duración o estado. Otro profesional o instante con la misma clave se rechaza. Repetir una cita cancelada devuelve esa cita cancelada y nunca la reactiva. Para volver a reservar se necesita una nueva solicitud.

El resumen GET no crea una reserva ni retiene un horario. Si el slot se ocupa entre el resumen y la confirmación, la operación muestra un error y vuelve a una página válida de disponibilidad; si el profesional dejó de ser consultable, vuelve al directorio. Se evita redirigir repetidamente al resumen inválido.

## Transacciones y orden de bloqueos

Orden fijo para creación y cambios de citas:

1. Fila existente de `patient_profiles`, con `lockForUpdate`.
2. Fila existente de `therapist_profiles`, con `lockForUpdate`.
3. Usuarios necesarios, en orden ascendente de ID en creación; el usuario actor en cancelación y asistencia.
4. Filas de citas y lecturas actuales del calendario.

La creación no se apoya en una consulta de citas que pudiera estar vacía. Los perfiles estables serializan tanto reservas del mismo profesional como reservas del mismo paciente con profesionales diferentes. La disponibilidad se relee dentro de la transacción con lecturas de bloqueo de usuarios, perfil, horarios, bloqueos y citas. Se reintentan hasta tres veces los errores transaccionales que Laravel reconoce como conflictos de concurrencia.

`TherapistCalendarService` centraliza la transacción de cambios semanales, presentación/aceptación y creación/eliminación de bloqueos. Bloquea primero el perfil profesional y luego su usuario. **Nunca adquiere un perfil de paciente después del profesional.** Comparte así el mutex profesional con las reservas. Guarda y comprueba dentro de la misma transacción; si deja una cita no cancelada con ocupación todavía vigente fuera de un tramo o cubierta por un bloqueo, rechaza y revierte el cambio. No cancela ni modifica citas silenciosamente.

La eliminación de cuenta toma primero su perfil de paciente, luego el profesional si existe, después el usuario y comprueba el historial. Las claves foráneas aportan una segunda protección a la conservación de citas.

## Archivos principales

- Enum: `app/Enums/AppointmentStatus.php`.
- Modelos: `Appointment`, `PatientProfile`; relaciones añadidas a `User` y `TherapistProfile`.
- Servicios: `AppointmentService`, `TherapistCalendarService`; `AvailabilityService` ahora excluye citas y permite reutilizar la comprobación de cobertura de tramos.
- Controladores: `AppointmentController`, `DashboardController`; integración en `TherapistScheduleController`, `ScheduleBlockController`, `TherapistDirectoryController` y protección de borrado en `ProfileController`.
- Vistas: `resources/views/appointments/{review,index,show,agenda}.blade.php`, componente `appointment-card`; enlaces en disponibilidad, próxima cita y agenda en el panel, navegación por rol y mensajes de eliminación de cuenta.
- Esquema: migración aditiva indicada arriba. No se alteraron las migraciones anteriores.
- Pruebas: `AppointmentTest`, `MySqlAppointmentConcurrencyTest`, proceso independiente `tests/Support/appointment-race-worker.php`. Las pruebas anteriores se conservan.

## Rutas nuevas

| Método | Ruta | Nombre | Acceso |
| --- | --- | --- | --- |
| GET | `/mis-citas?tab=upcoming\|history` | `appointments.index` | Paciente, solo propias, paginado |
| GET | `/reservas/revisar` | `appointments.review` | Paciente; profesional, fecha e instante en query |
| POST | `/reservas` | `appointments.store` | Paciente para sí mismo |
| GET | `/agenda?date=AAAA-MM-DD` | `appointments.agenda` | Profesional, solo su agenda diaria |
| GET | `/citas/{appointment}` | `appointments.show` | Paciente o profesional propietarios |
| POST | `/citas/{appointment}/cancelar` | `appointments.cancel` | Propietarios y transición válida |
| PATCH | `/citas/{appointment}/asistencia` | `appointments.outcome` | Profesional propietario, después del término |

Todas requieren cuenta autenticada, activa y con rol válido. Citas ajenas devuelven 404. El panel mantiene `/dashboard`, ahora con un controlador que obtiene la próxima cita real. La agenda permite fechas históricas estrictamente válidas; el horizonte de 60 días se aplica a reservas nuevas.

## Comprobaciones reales

- Laravel Pint aplicado; comprobación posterior correcta.
- **`php artisan test`: 94 pruebas aprobadas y 611 aserciones**, con la bandera de concurrencia activada. Incluye los 65 tests anteriores, 27 tests funcionales de reservas y dos tests MySQL reales.
- Las pruebas funcionales usan SQLite `:memory:` forzado por `phpunit.xml`. Solo la clase de concurrencia cambia su conexión de aplicación a la base exclusiva `beehappy_concurrency_test`; nunca usa `beehappy` como base de fixtures.
- **Concurrencia real MySQL: aprobada**, no sustituida por pruebas secuenciales o SQLite. Cada caso lanza dos procesos PHP con conexiones PDO independientes, espera que ambos estén listos y libera una barrera común. Caso 1: dos pacientes solicitan el mismo slot del mismo profesional. Caso 2: el mismo paciente solicita a dos profesionales para el mismo instante. Cada caso deja una sola reserva y una respuesta de conflicto de disponibilidad.
- La base exclusiva se verifica por nombre y las tres tablas relevantes por motor InnoDB. Se reutilizan las credenciales locales existentes; no se cambió `.env` ni ninguna credencial. Los fixtures de esta base y los archivos de barrera temporales se conservan: las pruebas no hacen truncados, borrados ni rollback de esquemas.
- **`npm run build`: correcto**, 59 módulos procesados por Vite.
- **`php artisan view:cache`: correcto**. Las pruebas HTTP renderizan resumen, listas, agenda, detalles, panel y validaciones.
- **`php artisan route:list`: correcto**, 38 rutas totales; siete rutas nuevas.
- **`git diff --check`: correcto**.
- Se verificó explícitamente driver MySQL, host local y base efectiva `beehappy` mediante una lectura antes de ejecutar la migración. **`php artisan migrate`: aplicada**; `migrate:status` la muestra en el lote 4.
- No se reinstalaron dependencias, modificaron roles de cuentas, borraron datos del proyecto ni realizaron commit o push. Los borrados de fixtures y comprobaciones de eliminación dentro de los tests funcionales ocurren exclusivamente en SQLite en memoria.

### Repetir las pruebas

En este entorno las extensiones SQLite ya instaladas se habilitan mediante un directorio INI temporal heredado por Artisan, como se documentó en 2A. Para ejecutar también la concurrencia:

```powershell
$env:PHP_INI_SCAN_DIR = Join-Path $env:TEMP 'beehappy-stage02a-check'
$env:BEEHAPPY_RUN_MYSQL_CONCURRENCY = '1'
php artisan test
```

Para ejecutar solo los casos MySQL:

```powershell
$env:BEEHAPPY_RUN_MYSQL_CONCURRENCY = '1'
php artisan test --filter=MySqlAppointmentConcurrencyTest
```

Sin esa bandera, las dos pruebas MySQL se marcan explícitamente como omitidas. Si MySQL local no está disponible o la credencial existente no permite preparar la base exclusiva, también se omiten con su motivo; esa ejecución no acredita concurrencia MySQL. En la ejecución registrada arriba sí se ejecutaron y aprobaron. La base de pruebas usa el nombre fijo `beehappy_concurrency_test`, nunca la base funcional. Sus datos pueden acumularse entre ejecuciones; cada carrera crea sus propios participantes y comprueba solo sus citas.

## Pruebas manuales

1. Entra como apiterapeuta y configura, en un día futuro dentro del horizonte, tramos `09:00–13:00` y `14:00–18:00`. Deja habilitada la aceptación de citas.
2. Entra como paciente. Desde **Ver apiterapeutas**, abre el profesional, elige la fecha y pulsa un horario. Revisa nombre, fecha, zona y duración. Antes de confirmar no debe existir una cita nueva.
3. Confirma. Comprueba el detalle **Agendada**, la advertencia sobre el pago y la próxima cita del panel. La reserva debe aparecer en **Mis citas**, y el horario debe desaparecer de disponibilidad.
4. Abre otra sesión de paciente e intenta confirmar el mismo horario. Debe aparecer el mensaje de disponibilidad actualizada. El primer paciente tampoco puede reservar una sesión que se solape con otro profesional.
5. Como profesional, abre **Agenda**, selecciona la fecha y revisa nombre del paciente, horario y estado. Comprueba un día vacío.
6. Con una cita vigente, intenta quitar su tramo o añadir un bloqueo que la cubra: debe rechazarse sin cambiar la cita ni el calendario guardado.
7. Cancela una cita futura desde una de las cuentas propietarias. Verifica autor, fecha y motivo en el detalle; la cita queda **Cancelada** en el historial y el slot vuelve a estar disponible. Repetir el POST de cancelación no modifica la auditoría.
8. Repite el POST de creación original con su UUID: debe devolver la misma cita, incluso cancelada. Otra hora con ese UUID debe rechazarse. Una reserva nueva exige una nueva solicitud.
9. Después del término de una cita agendada, el profesional puede marcar **Realizada** o **No asistió**. Antes del término, desde la cuenta del paciente o sobre una cita ajena, el servidor debe rechazarlo. Tampoco se puede reactivar una cancelada.
10. Intenta consultar o cancelar el ID de una cita ajena directamente por URL: debe devolver 404 y no cambiarla. Verifica historial con más de 12 filas para revisar la paginación.
11. Intenta eliminar desde Perfil una cuenta con historial, incluso todo cancelado. Debe mostrarse el error, mantenerse la cuenta y conservarse la sesión y las citas.
12. Revisa escritorio y 390 px: selector de fecha, resumen, confirmación, detalle, agenda, historial, mensajes y navegación inferior. El espacio inferior debe permitir alcanzar el último botón.

## Pendientes y límites

- **Navegador:** pendiente revisión visual real en escritorio y 390 px. No se dispone de herramienta de navegador habilitada; el lanzamiento local de Edge había sido rechazado por la política de permisos en 2A. No se afirma validación visual ni se generaron capturas en esta etapa. Sí se comprobaron compilación y renderizado HTTP de las vistas.
- **MySQL:** no queda pendiente la prueba de concurrencia de esta etapa: ambos casos reales aprobaron. Esto valida los flujos del servicio implementados; modificaciones manuales de citas por SQL no participan de su protocolo de bloqueos.
- Packs, pagos, notificaciones externas, reprogramación y cualquier etapa posterior quedan fuera del alcance. La implementación termina en 2B.
