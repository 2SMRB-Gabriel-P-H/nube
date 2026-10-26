# Nube Privada

Nube Privada es una aplicación web de almacenamiento personal y compartición de archivos, desarrollada en PHP. Cada cuenta tiene su propio espacio de archivos y puede compartir carpetas o documentos con otras cuentas.

## Funciones

- Registro, inicio y cierre de sesión.
- Gestión de archivos y carpetas: subir, crear, ver, descargar, renombrar, mover, copiar y eliminar.
- Organización por categorías y consulta de archivos recientes.
- Edición de archivos de texto desde el navegador.
- Edición de documentos, hojas de cálculo y presentaciones mediante OnlyOffice Document Server.
- Compartición de archivos y carpetas con permisos de lectura o edición completa.
- Administración de usuarios.
- Cifrado de archivos en reposo con AES-256-GCM y una clave de datos por usuario.
- Cuota predeterminada configurable en el código; revisa `fs.php` para ajustar las reglas a tu instalación.

## Tecnologías y requisitos

- PHP 8 o posterior.
- Apache HTTP Server.
- MariaDB o MySQL, accesible desde PHP mediante PDO.
- Extensiones PHP `pdo_mysql`, `openssl` y `mbstring`.
- OnlyOffice Document Server, necesario únicamente para editar documentos de oficina.
- `setfacl` (paquete ACL) en Linux si Apache necesita leer una clave generada por otra cuenta del sistema.

El código contiene sintaxis de PHP 8, como `str_contains`; versiones anteriores no son compatibles.

## Estructura principal

| Ruta | Descripción |
| --- | --- |
| `index.php` | Explorador principal de archivos. |
| `login.php`, `registro.php`, `logout.php` | Acceso y cuentas. |
| `admin.php` | Administración de usuarios. |
| `auth.php` | Sesiones, autenticación y permisos. |
| `db.php` | Conexión PDO y creación de tablas. |
| `fs.php` | Operaciones con archivos, rutas, cuota y categorías. |
| `share.php`, `compartir.php` | Reglas y acciones de compartición. |
| `actions.php`, `upload.php` | Acciones y subidas de archivos. |
| `view.php`, `raw.php`, `public.php` | Previsualización, entrega de archivos y contenido compartido. |
| `textedit.php`, `textsave.php`, `newdoc.php`, `convert.php` | Edición de texto y documentos. |
| `office.php`, `office_autosave.php`, `office_callback.php` | Integración con OnlyOffice. |
| `crypto.php` | Claves de cifrado y cifrado/descifrado de archivos. |
| `files/` | Almacén de archivos por usuario. Se crea y rellena durante el uso. |
| `cache/` | Caché y datos auxiliares. |
| `private/nube_master.key` | Clave maestra local de esta instalación; no debe publicarse. |
| `private/linux/ejecutable.sh` | Generador de clave para Linux. |
| `private/windows/ejecutable.bat` | Generador de clave para Windows. |
| `nube_privada.sql` | Volcado local de la base de datos, si se conserva en esta copia. No debe publicarse. |
| `.gitignore` | Excluye el almacén, la caché, la clave y el volcado SQL de Git. |

## Instalación

1. Instala Apache, PHP y MariaDB/MySQL. Habilita PHP para Apache y las extensiones indicadas arriba.
2. Coloca el proyecto en el servidor y configura un VirtualHost o alias de Apache para servirlo por HTTPS.
3. Ejecuta el generador de clave **una vez por cada instalación**:
   - Linux, desde la raíz del proyecto: `./private/linux/ejecutable.sh`
   - Windows, desde la raíz del proyecto: `private\windows\ejecutable.bat`
4. Asegúrate de que la cuenta de servicio de Apache/PHP pueda leer `private/nube_master.key` y escribir en `files/` y `cache/`.
5. Configura las variables de entorno de base de datos si no vas a usar los valores predeterminados.
6. Abre la aplicación y crea una cuenta. El registro no asigna automáticamente permisos de administrador.
7. Promueve la cuenta inicial desde MariaDB/MySQL, sustituyendo el nombre de usuario:

   ```sql
   UPDATE usuarios SET es_admin = 1 WHERE usuario = 'tu_usuario';
   ```

Los generadores crean una clave aleatoria nueva y se niegan a reemplazar una ya existente. Al clonar el repositorio, la clave no estará incluida porque Git la ignora; genera una en cada servidor. La clave maestra es propia de cada instalación.

## Base de datos

`db.php` admite estas variables de entorno:

| Variable | Valor predeterminado | Descripción |
| --- | --- | --- |
| `NUBE_DB_HOST` | `127.0.0.1` | Servidor de base de datos. |
| `NUBE_DB_PORT` | `3306` | Puerto. |
| `NUBE_DB_USER` | `root` | Usuario de MariaDB/MySQL. |
| `NUBE_DB_PASS` | vacío | Contraseña del usuario. |
| `NUBE_DB_NAME` | `nube_privada` | Nombre de la base de datos. |

La aplicación crea la base de datos y las tablas al conectarse por primera vez. Para ello, el usuario configurado debe tener permisos suficientes de inicialización. En una instalación de producción, configura un usuario dedicado con una contraseña y privilegios mínimos en lugar de usar `root`.

El esquema contiene principalmente las tablas `usuarios` y `compartidos`; `db.php` también añade algunas columnas que falten. No uses el mismo nombre de base de datos en dos instalaciones si quieres mantenerlas aisladas.

## Clave maestra y cifrado

La aplicación lee la clave desde la variable `NUBE_MASTER_KEY` (Base64), desde la ruta `NUBE_MASTER_KEY_FILE`, o, si no se establece ninguna, desde `private/nube_master.key`.

- Cada instalación debe tener su propia clave.
- No la guardes en GitHub ni en otro repositorio compartido. `.gitignore` la excluye y Apache deniega el acceso web a los archivos dentro de `private/`.
- Conserva una copia de seguridad privada de la clave junto con la base de datos y los archivos cifrados.
- Si pierdes la clave, los archivos cifrados podrían quedar irrecuperables. No generes otra sobre una instalación ya usada.
- Para migrar una instalación con archivos cifrados, traslada de forma segura la clave original junto con la base de datos y los archivos. Una clave nueva solo sirve para una instalación independiente o datos nuevos.

El contenido se cifra con AES-256-GCM por bloques. Las claves de datos por usuario se almacenan protegidas con la clave maestra en la base de datos.

## Apache, PHP y subidas

El archivo `.htaccess` desactiva el listado de directorios, deniega el acceso a varios archivos internos y define límites de carga/tiempo muy amplios. Sus directivas `php_value` requieren `mod_php`; si Apache usa PHP-FPM, configura esos límites en el pool de FPM y comprueba `AllowOverride` para las demás directivas.

Revisa los límites de PHP y del servidor antes de aceptar archivos grandes. No concedas permisos globales como `0777`; permite escritura solo a la cuenta del servicio web en las carpetas necesarias.

## OnlyOffice

OnlyOffice Document Server es opcional. Para que funcione la edición, el navegador debe acceder a la API de OnlyOffice y el Document Server debe poder descargar documentos y enviar los callbacks de guardado a la aplicación. Revisa la URL, el proxy, HTTPS y la autenticación/JWT del Document Server en `office.php` y en la configuración de tu despliegue.

La configuración actual incluye direcciones de integración específicas del servicio de documentos; adapta esos valores a la red y al hostname de cada servidor antes de desplegarlo en otro equipo.

## Copias de seguridad y restauración

Para restaurar completamente una instalación, conserva en una copia privada y coherente:

1. La base de datos.
2. El directorio `files/`.
3. La clave maestra `private/nube_master.key`.
4. La configuración de entorno y de Apache/PHP necesaria para el servicio.

El archivo `nube_privada.sql` es un volcado local de base de datos. Puede contener hashes de contraseñas, cuentas y otros datos; mantenlo fuera del repositorio y limita quién puede leerlo. La regla de Git y la protección de Apache reducen el riesgo de publicación accidental, pero no sustituyen el control de acceso ni una copia segura.

Prueba la restauración en un entorno independiente antes de depender de las copias. La base de datos, los archivos y la clave deben corresponder a la misma instalación.

## Seguridad antes de exponer el servicio

- Usa HTTPS y contraseñas robustas.
- Desactiva el registro público si no lo necesitas y controla quién recibe permisos de administrador.
- Guarda las claves y credenciales fuera del control de versiones.
- Revisa `files/`: está dentro del árbol de la aplicación; para un despliegue público, muévelo fuera del DocumentRoot o configura Apache para denegar por completo el acceso web directo a ese directorio.
- Revisa la política de cuotas y elimina excepciones de usuario codificadas en el código antes de reutilizarlo.
- Antes de publicar el código, mueve los secretos de firma que queden codificados en PHP a variables de entorno y rótalos.
- Mantén PHP, Apache, MariaDB/MySQL y OnlyOffice actualizados.
- Realiza y prueba copias de seguridad periódicas.

Este README describe el comportamiento del código y una configuración general; revisa los valores locales antes de instalar la aplicación en producción.
