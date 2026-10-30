# Sistema de Subdirección — CBT No. 6 Gerardo Linares Terrón

Plataforma web para el seguimiento docente: estructura académica, planeaciones PDF
(carga masiva, versiones y seguimiento de entregas) y administración de cuentas.

**Todo lo que usa es gratuito:** PHP 8.3, Apache, PostgreSQL 16, Docker,
Render (plan Free) y Neon (plan Free, sin vencimiento y sin tarjeta).

---

## 1. Publicarlo en la nube (gratis) — recomendado

Necesitas tres cuentas gratuitas: **GitHub**, **Neon** y **Render**.

### Paso 1 · Subir el código a GitHub
1. Crea una cuenta en <https://github.com> y un repositorio **privado** llamado `subdireccion`.
2. Sube el contenido de esta carpeta. Desde la página del repositorio: *Add file → Upload files*,
   arrastra todas las carpetas y archivos y presiona *Commit changes*.

### Paso 2 · Crear la base de datos en Neon
1. Entra a <https://neon.com> y regístrate (con tu cuenta de GitHub o de Google).
2. Crea un proyecto: nombre `subdireccion`, PostgreSQL 16, región **AWS US East (Ohio)** o la más cercana.
3. En *Connection details* copia la **cadena de conexión**. Se ve así:
   `postgresql://neondb_owner:XXXX@ep-algo-123.us-east-2.aws.neon.tech/neondb?sslmode=require`
   Guárdala; es una contraseña.

> No necesitas cargar ningún SQL a mano: el sistema crea las tablas y carga los datos
> institucionales (personas, usuarios, grupos, asignaciones…) automáticamente la primera vez que arranca.

### Paso 3 · Publicar la aplicación en Render
1. Entra a <https://render.com> y regístrate con tu cuenta de GitHub.
2. *New → Blueprint*, elige el repositorio `subdireccion`. Render leerá `render.yaml`.
3. Cuando te pida **DATABASE_URL**, pega la cadena de Neon del paso anterior.
4. Presiona *Apply*. La primera construcción tarda de 5 a 10 minutos.
5. Tu sistema quedará en una dirección como `https://subdireccion-xxxx.onrender.com`.

### Paso 4 · Primer acceso de la Subdirectora
La cuenta de **GEORGINA GARCÉS RODRÍGUEZ (usuario `acceso033`)** conserva la contraseña que ya
tenía en XAMPP. Si no la recuerdas o es la primera vez:

1. En Render abre el servicio → *Environment* y copia el valor de **SETUP_TOKEN**.
2. Abre `https://TU-DIRECCION.onrender.com/configuracion_inicial.php`, pega el código,
   define una contraseña de al menos 12 caracteres y guarda.
3. **Importante:** vuelve a *Environment* y **borra SETUP_TOKEN** (*Save changes*).
   Eso desactiva esa página.
4. Entra por `/login.php` con el usuario `acceso033`.

### Paso 5 · Activar al resto del personal
Menú → **Administración de cuentas** → elige la persona → *Generarla automáticamente* → *Aplicar*.
El sistema muestra una contraseña temporal **una sola vez**; entrégala por un canal privado.
Al entrar, el sistema obliga a cada persona a definir su propia contraseña.

### Lo que debes saber del plan gratuito
| Aspecto | Qué pasa | Qué hacer |
|---|---|---|
| Render “duerme” tras 15 min sin uso | La primera visita tarda ~1 minuto en abrir | Normal; las siguientes son rápidas |
| Neon gratis: 1 GB | Los PDF se guardan en la base (≈ 500 a 1 000 planeaciones de 1–2 MB) | Comprime PDFs grandes; si se llena, Neon Launch o un servidor propio |
| Render borra su disco al reiniciar | Por eso PDFs y sesiones se guardan en la base de datos | Nada; ya está resuelto |
| 750 h/mes de Render | Suficiente para un servicio encendido todo el mes | — |

**Respaldos:** en Neon → *Branches* puedes restaurar la base a cualquier momento de las últimas
horas. Para una copia propia: `pg_dump "CADENA_DE_NEON" > respaldo.sql`.

---

## 2. Ejecutarlo en tu computadora o en un servidor propio

Requiere **Docker Desktop** (gratuito).

```bash
cp .env.example .env          # edita DB_PASSWORD y, si quieres, SETUP_TOKEN
docker compose up -d --build
```
Abre <http://localhost:8080>. Para detenerlo: `docker compose down` (los datos se conservan).

Esta misma opción sirve para un servidor gratuito permanente (por ejemplo, Oracle Cloud
*Always Free*), donde no hay límite de 1 GB para los PDF.

---

## 3. Qué se corrigió respecto a la versión de XAMPP

**Errores que impedían ejecutar o desplegar**
- La migración `01_migracion_planeacion.sql` fallaba: la llave foránea usaba `INT` contra
  `BIGINT UNSIGNED`, por eso la tabla `planeacion_archivo_hash` no existía y la carga masiva
  no podía registrar nada. También cambiaba `parcial_id` a `INT`, lo que rompía otra llave.
- `ver_planeacion.php` usaba `str_starts_with()` y la carga usaba `$_FILES['full_path']`,
  que no existen en el PHP 7.4 del volcado: el visor de PDF fallaba.
- PHP acepta 20 archivos por envío de forma predeterminada; los lotes de hasta 100 PDF se
  cortaban en silencio. Ahora se configura `max_file_uploads = 100`.
- La vista dependía de `DEFINER=root@localhost`, que falla en cualquier servidor en la nube.
- La activación inicial solo funcionaba desde `localhost`, lo que en la nube impedía el primer
  acceso. Se sustituyó por `configuracion_inicial.php` con código `SETUP_TOKEN`.
- Rutas fijas de Windows (`C:\xampp\...`) y credenciales escritas en el código. Ahora todo se
  configura con variables de entorno.

**Errores de funcionamiento**
- Al elegir una carpeta, cualquier archivo que no fuera PDF (`desktop.ini`, `Thumbs.db`)
  rechazaba el lote completo; ahora se omite y se avisa.
- Con `webkitdirectory` no se podían elegir archivos sueltos aunque la pantalla lo ofrecía;
  ahora hay selector “carpeta / varios archivos”.
- La confirmación mostraba asignaciones de cualquier periodo; ahora solo las del periodo elegido.
- Se podían registrar dos PDF para la misma asignatura en un lote, y una nueva entrega no
  sustituía a la anterior (quedaban dos “vigentes”). Ahora la nueva versión reemplaza a la
  anterior y esta queda en el historial (`planeacion_anterior_id`).
- Los docentes veían todas las planeaciones pero no podían abrir ningún PDF. Ahora cada docente ve
  y abre solo las suyas; Subdirección, Apoyo y Dirección ven todas.
- La búsqueda ignora acentos y mayúsculas igual que en MySQL (“ingles” encuentra “INGLÉS”).
- La cookie de sesión no se marcaba como segura detrás del HTTPS de la nube.
- Textos que decían “sin autenticación” o “prototipo local” ya no aplicaban.

**Agregado para uso en la nube**
- Cambio de contraseña (pendiente en el LEEME original) y cambio obligatorio tras activar o restablecer.
- Restablecer contraseña y suspender cuentas desde Administración de cuentas.
- Límite de 5 intentos fallidos de inicio de sesión por usuario cada 15 minutos.
- Registro en `auditoria` de activaciones, cambios de contraseña y cargas de planeaciones.
- Vista **Seguimiento de entregas**: por grupo, qué asignaturas tienen semestral y cada parcial.
- Indicadores en el inicio.
- Encabezados de seguridad (CSP, HSTS, nosniff) y `health.php` para el monitoreo de Render.

---

## 4. Estructura del proyecto

```
app/            Lógica (conexión, sesión, permisos, migrador). No es accesible desde la web.
database/       001_esquema.sql (tablas) y 002_datos.sql (datos institucionales migrados).
public/         Páginas web (lo único que se publica).
docker/         Configuración de PHP y arranque del contenedor.
tests/e2e.py    Prueba automática de punta a punta (50 verificaciones).
Dockerfile, docker-compose.yml, render.yaml, .env.example
```

Para cambios de base de datos futuros, agrega `database/003_descripcion.sql`; se aplica solo al
siguiente arranque y nunca se repite.

## 5. Variables de entorno

| Variable | Uso |
|---|---|
| `DATABASE_URL` | Cadena de conexión PostgreSQL (Neon/Render/Supabase). |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SSLMODE` | Alternativa a `DATABASE_URL`. |
| `SETUP_TOKEN` | Habilita `/configuracion_inicial.php` (mín. 16 caracteres). Bórrala después de usarla. |
| `PLANEACIONES_MAX_MB` | Tamaño máximo por PDF (15 por defecto). |
| `PLANEACIONES_MAX_ARCHIVOS` | PDF por lote (60 por defecto). |
| `SESSION_TTL` | Duración de la sesión en segundos (8 h por defecto). |
| `APP_TIMEZONE` | Zona horaria (America/Mexico_City). |

## 6. Siguientes pasos sugeridos
- Revisión y Vo. Bo. de planeaciones (los campos `resultado_revision`, `usuario_revision` ya existen).
- Módulos 03 y 04: sesiones, observaciones, diálogo reflexivo, acuerdos y seguimiento
  (las tablas ya están creadas).
- Carga individual de planeaciones por cada docente.
