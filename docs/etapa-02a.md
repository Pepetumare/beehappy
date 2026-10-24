# Etapa 2A — Horarios, bloqueos y disponibilidad

## Alcance y base revisada

Se revisaron modelos, rutas, autenticación y componentes de la etapa existente. No existían `AGENTS.md` ni `docs/etapa-01.md` al comenzar. Se conservan Laravel 12, Blade, Tailwind y Alpine, junto con la identidad BeeHappy y los formularios de autenticación y perfil.

Se implementa configuración semanal, bloqueos privados y consulta de disponibilidad. Consultar una hora no guarda ninguna reserva. No se incorporan reservas, packs, pagos ni citas ficticias.

## Datos y propiedad

Migración aditiva: `database/migrations/2026_10_24_000000_create_therapist_availability_tables.php`.

- `users.is_active`: booleano, activo por defecto. No se admite en los formularios públicos de perfil.
- `therapist_profiles`: usuario único, comuna, presentación y `accepts_appointments` (verdadero por defecto). Los apiterapeutas existentes reciben un perfil; los nuevos lo reciben al registrarse. Sin tramos, un perfil no tiene disponibilidad aunque figure en el directorio.
- `therapist_schedules`: perfil profesional, día ISO (lunes 1 a domingo 7), inicio, término y timestamps. Los tiempos son horas locales semanales de Santiago.
- `schedule_blocks`: perfil, inicio UTC, término UTC, motivo privado opcional y timestamps.

Claves foráneas con eliminación en cascada y relaciones Eloquent. Índices compuestos `therapist_schedule_day_start` y `therapist_block_interval`, con nombres compatibles con MySQL. La migración permite continuar tras una interrupción del DDL de MySQL; no reemplaza perfiles ni su presentación al reejecutarse.

El middleware `role` exige autenticación, rol correspondiente y cuenta activa. La configuración obtiene siempre el perfil desde el usuario autenticado. Los identificadores de propietario enviados por el cliente se ignoran. La eliminación de bloqueos busca el ID exclusivamente dentro de la relación del propietario y devuelve 404 si pertenece a otra persona. Las actualizaciones semanales usan una transacción y bloqueo del usuario para serializar los guardados del mismo apiterapeuta.

Los valores almacenados de rol son exactamente `paciente` y `apiterapeuta`, centralizados en las constantes de `User`. No existe un enum de roles ni un tercer rol implementado en esta versión. `therapist` es un término técnico usado en tablas, clases y nombres de rutas; **no es un alias válido de `apiterapeuta`**. Tampoco son roles válidos `patient` o `admin`. Los métodos `isPatient()`, `isTherapist()`, `hasKnownRole()` y `roleLabel()` del modelo se reutilizan para menús, paneles y autorización. Los valores desconocidos se rechazan, sin convertir ni reescribir cuentas. Véase `docs/roles.md` para la revisión completa.

El directorio está paginado y restringido a pacientes activos. Solo incluye usuarios activos con rol apiterapeuta y perfiles que aceptan citas. Las vistas exponen nombre, comuna, presentación y disponibilidad; no muestran correo de acceso, motivos de bloqueos ni datos de pacientes. La presentación se escapa mediante Blade. El motivo se oculta también en la serialización del modelo de bloqueo.

## Servicio y reglas temporales

`app/Services/AvailabilityService.php` centraliza el cálculo. `config/appointments.php` define:

| Parámetro | Valor inicial |
| --- | --- |
| Zona | America/Santiago |
| Duración de sesión | 45 minutos |
| Separación | 0 minutos |
| Horizonte | 60 fechas, incluyendo hoy y hasta hoy + 59 días |

Las fechas se validan estrictamente como `AAAA-MM-DD` y con calendario real. La consulta rechaza fechas pasadas y fuera del horizonte en el servidor, también si se llama directamente al servicio. Los bloqueos admiten fechas concretas fuera del horizonte para poder planificar cierres futuros.

Cada tramo debe terminar dentro del mismo día y después de comenzar. Se rechazan solapamientos; se permiten tramos contiguos. Se admiten hasta 100 tramos semanales y se puede guardar una semana vacía. Las sesiones se generan por tramo, solo si caben completas, y no atraviesan descansos. Una sesión que ya comenzó se excluye; si comienza exactamente ahora, puede aparecer.

Todos los intervalos usan `[inicio, fin)`. Un bloqueo afecta una sesión si `inicio_bloqueo < fin_sesión` y `fin_bloqueo > inicio_sesión`. Un bloqueo que termina justo al comenzar una sesión, o empieza justo al terminarla, no la excluye.

Los bloqueos se convierten a UTC antes de persistirlos y se interpretan explícitamente en UTC al leerlos, independientemente de la zona de la aplicación. Se muestran nuevamente en Santiago. Un día completo comprende el primer instante de la fecha local y la medianoche de la fecha local siguiente, sin sumar 24 horas fijas. Las pruebas cubren días de 23 y 25 horas.

Se usan las transiciones reales de America/Santiago, sin offsets fijos. Las horas locales inexistentes se rechazan en bloqueos parciales. Un tramo semanal con un extremo inexistente no ofrece sesiones ese día. Para una hora repetida se elige la primera ocurrencia del inicio y la última del término; se comprueba además que ninguna sesión atraviese un descanso local durante el retroceso del reloj. La duración se mide en minutos reales sobre UTC. Las etiquetas muestran los desfases de cada extremo para distinguir horas repetidas.

La salida del servicio contiene `starts_at` y `ends_at` en ISO 8601 UTC y una etiqueta local. Un usuario inactivo, de otro rol o que no acepta citas produce disponibilidad vacía. El controlador público devuelve 404 cuando el perfil deja de ser consultable.

### Incorporación futura de reservas

Actualización: esta incorporación ya está implementada en la etapa 2B. Véase `docs/etapa-02b.md` para las reservas, exclusión de ocupación, cambios protegidos del calendario y pruebas reales MySQL. El resto de esta sección conserva la descripción del alcance original de 2A.

Dentro de `AvailabilityService::forDate()`, después de excluir bloqueos y antes de añadir el slot al resultado, está señalado el punto donde se consultarán conflictos con reservas reales usando los mismos intervalos semiabiertos. Esa exclusión deberá acompañarse de validación transaccional y control de concurrencia al crear reservas; la consulta de disponibilidad por sí sola no garantiza una reserva futura.

## Archivos de esta etapa

- Configuración y esquema: `config/appointments.php`, la migración indicada y `phpunit.xml` (fuerza SQLite en memoria para aislar pruebas).
- Modelos: `TherapistProfile`, `TherapistSchedule`, `ScheduleBlock` y relación, estado activo y creación de perfil en `User`.
- Servicio: `app/Services/AvailabilityService.php`.
- Seguridad y validación: `app/Http/Middleware/RequireRole.php`, `app/Http/Requests/UpdateTherapistSchedulesRequest.php`, alias en `bootstrap/app.php`.
- Controladores: `TherapistScheduleController`, `ScheduleBlockController`, `TherapistDirectoryController`; registro de usuario y perfil profesional dentro de una transacción en `Auth/RegisteredUserController`.
- Rutas: `routes/web.php`.
- Vistas: `resources/views/therapist/{schedules,blocks}.blade.php`, `resources/views/therapists/{index,availability}.blade.php`, componente `availability-feedback.blade.php`.
- Vistas compartidas: `dashboard.blade.php`, `layouts/navigation.blade.php` y `layouts/app.blade.php`. Saludo con primer nombre, banner reducido y acceso funcional al directorio; una sola tarjeta de perfil. Navegación por rol y espacio inferior que contempla el área segura del móvil.
- Pruebas: `tests/Feature/AvailabilityTest.php`. Se conservan todas las pruebas anteriores.

## Rutas nuevas

Todas requieren autenticación y usuario activo.

| Método | Ruta | Nombre | Rol |
| --- | --- | --- | --- |
| GET | `/mis-horarios` | `therapist.schedules.edit` | Apiterapeuta |
| PUT | `/mis-horarios` | `therapist.schedules.update` | Apiterapeuta |
| PATCH | `/mis-horarios/perfil-profesional` | `therapist.professional.update` | Apiterapeuta |
| GET | `/mis-horarios/bloqueos` | `therapist.blocks.index` | Apiterapeuta |
| POST | `/mis-horarios/bloqueos` | `therapist.blocks.store` | Apiterapeuta |
| DELETE | `/mis-horarios/bloqueos/{block}` | `therapist.blocks.destroy` | Apiterapeuta propietario |
| GET | `/apiterapeutas` | `therapists.index` | Paciente |
| GET | `/apiterapeutas/{therapist}?date=AAAA-MM-DD` | `therapists.show` | Paciente |

## Comprobaciones realizadas

- Laravel Pint aplicado a los archivos PHP de esta etapa; comprobación posterior correcta.
- `php artisan test`: **54 pruebas aprobadas, 228 aserciones**, incluidas las anteriores. SQLite `:memory:` forzado por `phpunit.xml`, sin usar MySQL para las pruebas.
- El PHP de consola tiene SQLite desactivado por defecto. Se cargaron sus extensiones ya instaladas mediante un directorio INI temporal heredado por el proceso de Artisan; no se reinstalaron dependencias ni se modificó el PHP global.
- `npm run build`: correcto; CSS y JavaScript generados por Vite.
- `php artisan route:list`: correcto, 31 rutas totales y ocho rutas nuevas de esta etapa (28 excluyendo rutas de proveedor).
- `php artisan view:cache`: correcto.
- `git diff --check`: correcto.
- `php artisan migrate`: migración aplicada en MySQL. El primer intento detectó un nombre de índice automático demasiado largo; se corrigió con nombres explícitos y la migración continuó sin borrar tablas ni datos. `migrate:status` confirmó su aplicación. Una auditoría adicional de claves en MySQL no pudo completarse después porque la conexión local dejó de estar disponible; las claves y los índices sí están verificados en la base aislada por pruebas.
- Se probó también la migración desde cero en una base SQLite temporal separada, preparada para revisión visual. No se añadieron usuarios ficticios a la base del proyecto.
- No se ejecutaron rollback, fresh, truncados, eliminación de bases, instalación de dependencias, commit ni push.

Para repetir las pruebas si PHP sigue sin tener SQLite activado, desde PowerShell:

```powershell
$testIniDir = Join-Path $env:TEMP 'beehappy-stage02a-check'
New-Item -ItemType Directory -Force -Path $testIniDir | Out-Null
[IO.File]::WriteAllText((Join-Path $testIniDir 'extensions.ini'), "extension=C:\php\ext\php_pdo_sqlite.dll`nextension=C:\php\ext\php_sqlite3.dll`n")
$env:PHP_INI_SCAN_DIR = $testIniDir
php artisan test
```

Estas rutas de extensiones corresponden al entorno revisado; en otro equipo deben adaptarse a su instalación de PHP.

## Prueba manual

1. Con MySQL disponible, inicia la aplicación y entra como apiterapeuta. Abre **Configurar horarios** desde el panel o **Horarios** en la navegación.
2. Añade, para un día futuro dentro de las siguientes 60 fechas, los tramos `09:00–13:00` y `14:00–18:00`. Guarda. Completa comuna y presentación; deja marcada la aceptación de citas.
3. Intenta añadir `12:30–14:30` el mismo día: debe aparecer un error de solapamiento y conservarse lo guardado. Comprueba también un tramo contiguo y otro con término anterior al inicio.
4. En otra sesión, entra como paciente, pulsa **Ver apiterapeutas**, abre el profesional y consulta la fecha elegida. Las sesiones deben durar 45 minutos y respetar el descanso del mediodía. Solo se muestran horas futuras.
5. Como apiterapeuta, crea un bloqueo parcial `09:45–10:30` para esa fecha con un motivo privado. Al volver a consultar como paciente, las sesiones `09:00–09:45` y `10:30–11:15` siguen disponibles y `09:45–10:30` desaparece. El motivo y el correo no deben verse.
6. Crea un bloqueo de día completo para esa misma fecha. La consulta debe mostrar **No hay horarios disponibles**. Elimínalo desde la cuenta propietaria y vuelve a consultar.
7. Entra con un segundo apiterapeuta: no debe ver los bloqueos ni los tramos del primero, y no puede eliminar su bloqueo cambiando el ID en la petición. Un paciente tampoco puede acceder a `/mis-horarios`.
8. Desmarca **Acepto citas**: el profesional desaparece del directorio y su página de disponibilidad devuelve 404. Vuelve a marcarlo para restaurar su publicación.
9. Prueba una fecha inválida o posterior a hoy + 59 días modificando la URL: el servidor debe rechazarla. Comprueba una fecha sin tramos para verificar el estado vacío.
10. Revisa escritorio y un viewport de 390 px: formularios, mensajes, botones, menú de cuenta, paginación y navegación inferior. Comprueba que el último control sea visible al desplazarte hasta el final.

## Pendientes

- **Revisión visual en navegador, escritorio y 390 px**: el lanzamiento de Edge fue rechazado por la revisión automática de permisos porque requería una autorización no disponible. No hay capturas ni se afirma que se haya realizado esta revisión. Las vistas sí fueron compiladas y renderizadas mediante pruebas HTTP.
- Repetir la auditoría adicional del esquema sobre MySQL cuando esté disponible la conexión local.
- Próximas etapas: reservas reales y exclusión de sus conflictos, packs y pagos. No forman parte de esta implementación.
