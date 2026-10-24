# Roles de BeeHappy

## Convención canónica

La revisión original no encontró un enum PHP de roles ni un cast a enum. La columna histórica `users.role` sigue siendo una cadena, con valor por defecto `paciente`. En la etapa 3A se añade expresamente el rol `administrador` para gestionar catálogos y consultar compras. No se modificaron los roles almacenados ni se creó una cuenta administradora. Véase `docs/etapa-03a.md`.

| Tipo de cuenta | Valor almacenado | Constante en User | Identificación |
| --- | --- | --- | --- |
| Paciente | `paciente` | `ROLE_PATIENT` | `isPatient()` |
| Apiterapeuta | `apiterapeuta` | `ROLE_THERAPIST` | `isTherapist()` |
| Administrador | `administrador` | `ROLE_ADMINISTRATOR` | `isAdministrator()` |

`therapist` **no es un valor válido de rol**, aunque aparezca como nombre de controlador, tabla, relación o ruta. Por eso asignarlo a una cuenta no activa el menú profesional. `patient` y `admin` tampoco son alias válidos. El administrador usa exactamente `administrador`; sus permisos nuevos se limitan al catálogo y consulta de compras. No hereda acceso a reservas ni horarios profesionales.

## Hallazgos y correcciones

Antes de la revisión, registro, middleware profesional y disponibilidad ya utilizaban `apiterapeuta`. Sin embargo, las vistas escogían entre apiterapeuta y paciente con un `else`: cualquier valor desconocido terminaba presentado como paciente. Además, `/dashboard` y `/profile` solo comprobaban autenticación, y el login no comprobaba la validez del rol.

La fuente de valores y etiquetas queda en las constantes `User::ROLE_PATIENT`, `User::ROLE_THERAPIST`, `User::ROLE_ADMINISTRATOR` y `User::roleOptions()`. `User::registrationRoleOptions()` contiene únicamente paciente y apiterapeuta: el registro público nunca permite crear administradores. No se añadió un enum de roles porque no existía uno y se conserva la representación actual. Las migraciones históricas permanecen intactas.

- `hasRole()` solo reconoce valores canónicos, incluso si el valor desconocido coincide con un parámetro de middleware.
- `hasKnownRole()` valida que exista en la convención actual.
- `isPatient()` e `isTherapist()` centralizan las comprobaciones de tipo de cuenta.
- `roleLabel()` proporciona las etiquetas; un valor desconocido nunca se etiqueta como paciente.
- `LoginRequest` usa `attemptWhen()` para rechazar cuentas inactivas o con rol desconocido antes de crear la sesión autenticada. Conserva la limitación de intentos y la respuesta de credenciales inválidas.
- El middleware `role` sin parámetros exige un rol conocido y cuenta activa en panel y perfil; con parámetros exige además uno de los roles permitidos.
- Registro, enlaces de inicio y selector de cuenta reutilizan los valores de `User`. El registro sigue limitado a los dos tipos públicos actuales.
- Menús, panel y perfil usan métodos de `User`, sin comparaciones de cadenas de rol en Blade ni conversión implícita de desconocidos a pacientes.
- El directorio profesional, servicio de disponibilidad y autorización del formulario de horarios reutilizan la misma convención.
- Factory y seeder declaran explícitamente el rol paciente. El seeder no se ejecutó sobre la base del proyecto durante esta revisión.

## Destinos y permisos

| Rol | Destino por defecto después del login | Menú y panel | Acceso del servidor |
| --- | --- | --- | --- |
| `paciente` activo | `/dashboard` | Inicio, Apiterapeutas, Perfil; panel paciente | Directorio y disponibilidad. Sin gestión profesional |
| `apiterapeuta` activo | `/dashboard` | Inicio, Horarios, Bloqueos, Perfil; panel profesional | Sus horarios, presentación y bloqueos. Sin directorio de paciente |
| `administrador` activo | `/dashboard` | Inicio, Sesiones, Compras, Perfil; panel administrativo | Gestión de todas las ofertas y consulta de todas las compras. No crea ni cancela compras de pacientes |
| Desconocido, incluyendo `therapist` y `admin` | Login rechazado | Sin acceso al panel ni menú de otro rol | 403 en rutas protegidas si ya existía una sesión |

Los tres roles usan la misma ruta de entrada, con contenido y navegación determinados por los métodos del modelo. Desde 3A el paciente tiene Mis compras y el profesional tiene Sesiones y Compras, además de los accesos anteriores. Se conserva la redirección `intended` de Laravel: intentar restaurar una URL de otro rol nunca evita la autorización del servidor, que responde 403.

Los bloqueos y horarios siguen asociados al perfil del usuario autenticado. Las pruebas existentes de propiedad y privacidad se conservaron. El formulario de perfil no permite cambiar el rol aunque se envíe como campo adicional.

## Archivos modificados por la revisión

- `app/Models/User.php`: constantes, métodos de rol y etiqueta, valor por defecto para nuevas instancias y creación del perfil profesional mediante el método compartido.
- `app/Http/Middleware/RequireRole.php` y `routes/web.php`: reconocimiento estricto, protección del panel y perfil y parámetros basados en constantes.
- `app/Http/Requests/Auth/LoginRequest.php`: condición de acceso al autenticar.
- `app/Http/Controllers/Auth/RegisteredUserController.php`: validación y valor por defecto desde `User`.
- `app/Http/Requests/UpdateTherapistSchedulesRequest.php`, `app/Models/TherapistProfile.php` y `app/Services/AvailabilityService.php`: eliminación de comprobaciones de rol duplicadas.
- `resources/views/dashboard.blade.php`, `layouts/navigation.blade.php`, `profile/edit.blade.php`, `auth/register.blade.php` y `welcome.blade.php`: métodos y constantes compartidos.
- `database/factories/UserFactory.php` y `database/seeders/DatabaseSeeder.php`: rol explícito para nuevos datos de prueba.
- `tests/Feature/RoleConsistencyTest.php`: regresiones de roles, menús, destino, acceso, registro, estado activo y preservación del rol almacenado.
- `docs/etapa-02a.md` y este documento: convención y límites reales del proyecto.

## Verificación

Los resultados numéricos que siguen corresponden a la revisión original anterior a 3A. Los resultados actuales y las pruebas del administrador están documentados en `docs/etapa-03a.md`.

Se ejecutan las pruebas con `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` y `DB_URL` vacío, forzados en `phpunit.xml`. Las extensiones SQLite instaladas se cargan mediante un directorio INI temporal para que también las herede el proceso de Artisan. No se consulta ni modifica la base de cuentas del proyecto.

El comando de pruebas específico es:

```powershell
php artisan test --filter='RoleConsistencyTest|AvailabilityTest|RegistrationTest|AuthenticationTest'
```

Resultado: **46 pruebas aprobadas, 338 aserciones**. Incluye propiedad de horarios y bloqueos, protección de datos privados y acceso cruzado entre usuarios. No se hicieron migraciones, actualizaciones de cuentas, commit ni push durante esta revisión.

Suite completa posterior: **65 pruebas aprobadas, 387 aserciones**, también sobre SQLite en memoria. Laravel Pint aplicado a los archivos PHP modificados y compilación de vistas Blade correcta. `git diff --check` sin errores.
