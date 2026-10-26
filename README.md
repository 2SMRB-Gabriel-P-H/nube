# Nube

Nube es una aplicación web personal de almacenamiento de archivos escrita en PHP. Permite que varios usuarios gestionen sus propios archivos y carpetas desde el navegador, compartan contenido entre cuentas y editen documentos compatibles con OnlyOffice.

> Este README describe la aplicación que se encuentra en `/mnt/interno-hdd/nube`. Es documentación del código y de su despliegue conocido; no sustituye las copias de seguridad ni una revisión de seguridad antes de exponerla a Internet.

## Funciones

- Registro, inicio y cierre de sesión.
- Espacio de archivos separado por usuario.
- Subida de archivos y carpetas, navegación, búsqueda, vistas por tipo y actividad reciente.
- Crear carpetas y documentos de texto; renombrar, mover, copiar y eliminar elementos.
- Previsualización y descarga de archivos compatibles.
- Compartición de archivos y carpetas entre usuarios, con permisos de lectura o edición completa.
- Edición de texto en el navegador.
- Edición de documentos de texto, hojas de cálculo y presentaciones con OnlyOffice, si el Document Server está disponible y configurado.
- Administración de cuentas desde el panel de administración.
- Cifrado de archivos en reposo mediante AES-256-GCM por bloques, cuando la clave maestra está configurada correctamente.

La cuota predeterminada definida en el código es de 15 GB decimales por usuario. El código concede cuota ilimitada a una cuenta configurada por nombre de usuario; revisa `fs.php` antes de cambiar cuentas o publicar el proyecto en otro entorno.

## Tecnologías

- PHP 8 con PDO y el controlador PDO MySQL/MariaDB.
- Apache HTTP Server.
- MariaDB (compatible con el protocolo MySQL que usa PDO).
- OpenSSL para el cifrado de archivos.
- OnlyOffice Document Server para editar documentos de oficina.
- JavaScript y CSS integrados en las páginas PHP.

Extensiones PHP utilizadas por el código: `pdo_mysql`, `openssl` y `mbstring`. `fileinfo` puede ser necesaria para la detección MIME del servidor y las vistas previas. Comprueba también que Apache tenga PHP habilitado.

## Estructura del proyecto

| Archivo o carpeta | Propósito |
| --- | --- |
| `index.php` | Explorador principal de archivos y carpetas. |
| `login.php`, `registro.php`, `logout.php` | Sesiones y cuentas. |
| `admin.php` | Administración de usuarios. |
| `auth.php` | Autenticación, sesiones, registro y permisos de administrador. |
| `db.php` | Conexión PDO y creación/migración básica de tablas. |
| `fs.php` | Rutas, cuota, operaciones del sistema de archivos, clasificación y acceso. |
| `share.php`, `compartir.php` | Reglas y acciones de compartición. |
| `actions.php`, `upload.php` | Acciones del explorador y subidas. |
| `view.php`, `raw.php`, `public.php` | Vistas previas, entrega de archivos y enlaces compartidos. |
| `textedit.php`, `textsave.php`, `newdoc.php`, `convert.php` | Edición de texto y documentos. |
| `office.php`, `office_autosave.php`, `office_callback.php` | Integración de edición y guardado con OnlyOffice. |
| `crypto.php` | Claves y cifrado/descifrado de datos. |
| `live.php` | Estado/actividad reciente de archivos. |
| `files/` | Datos de los usuarios, organizados en directorios separados. |
| `cache/` | Caché y datos auxiliares de la aplicación. |
| `.htaccess` | Límites PHP, protección de algunos archivos internos y desactivación de listado de directorios. |

No se encontró un `composer.json`, volcado SQL ni documentación de instalación dentro del directorio revisado. El esquema se crea y ajusta desde `db.php` al conectar la aplicación a la base de datos.

## Despliegue conocido

En esta máquina, `/var/www/html/nube` apunta a `/mnt/interno-hdd/nube`. El usuario del servidor web (`www-data`) tiene acceso de escritura a `files/` y `cache/`. Si el disco externo no está montado, o si cambian el enlace simbólico o los permisos, la aplicación puede dejar de ver los datos o no poder guardarlos.

La URL depende del VirtualHost de Apache. Con la ruta anterior y un `DocumentRoot` en `/var/www/html`, normalmente se accedería como `https://<dominio>/nube/`.

## Configuración

### Base de datos

`db.php` lee estas variables de entorno y admite valores predeterminados:

| Variable | Valor predeterminado en el código | Uso |
| --- | --- | --- |
| `NUBE_DB_HOST` | `127.0.0.1` | Host de MariaDB. |
| `NUBE_DB_PORT` | `3306` | Puerto de MariaDB. |
| `NUBE_DB_USER` | `root` | Usuario de la base de datos. |
| `NUBE_DB_PASS` | vacío | Contraseña. |
| `NUBE_DB_NAME` | `nube` | Nombre de la base de datos. |

Configura estas variables en el entorno de Apache/PHP, no en archivos públicos del sitio. Para producción, crea un usuario MariaDB dedicado con los permisos mínimos necesarios en vez de usar `root`. Ten en cuenta que el código actual intenta crear la base y las tablas; el usuario de MariaDB necesitará permisos para esa inicialización o la base tendrá que prepararse previamente y ajustarse el código.

Las tablas principales son `usuarios` y `compartidos`. El código añade ciertas columnas que falten al conectarse.

### Clave maestra del cifrado

`crypto.php` obtiene la clave maestra desde `NUBE_MASTER_KEY` (Base64) o desde el archivo indicado por `NUBE_MASTER_KEY_FILE`; si no se establece este último, usa `/etc/nube/nube_master.key`. La clave debe decodificar a un mínimo de 32 bytes.

- Guarda la clave fuera del directorio público y con permisos estrictos, legible por el proceso PHP.
- Haz una copia de seguridad segura de la clave junto con una copia de la base de datos y los archivos cifrados.
- Si se pierde o cambia la clave, los archivos cifrados existentes podrían no poder recuperarse.
- No incluyas la clave en Git, README, registros o tickets.
- El código también usa una constante interna para firmar tokens y funciones auxiliares. Antes de reutilizar o publicar el proyecto, conviene trasladar cualquier secreto de firma fuera del código fuente y rotarlo de forma planificada.

### Archivos y permisos

El código escribe en `files/` y `cache/`; ambas rutas deben ser escribibles por Apache. Limita los permisos al usuario/grupo del servicio web en vez de usar permisos globales. El código de registro crea directorios con permisos amplios, así que conviene endurecer esa configuración antes de exponer el registro públicamente.

El directorio `files/` está dentro del árbol de la aplicación, que está bajo el DocumentRoot en el despliegue descrito. Aunque `raw.php` implementa controles de acceso, no asumas que todas las rutas de archivos quedan protegidas contra acceso directo por Apache. Para producción, mueve los datos fuera del DocumentRoot o configura Apache para denegar completamente el acceso web a `/nube/files` y `/nube/cache`, manteniendo el acceso de PHP al sistema de archivos.

### Apache y PHP

- Configura un VirtualHost cuyo `DocumentRoot` o alias incluya la aplicación.
- Habilita PHP con un método compatible con la configuración local (por ejemplo, `mod_php` o PHP-FPM).
- Permite el uso de `.htaccess` (`AllowOverride`) si necesitas sus reglas.
- El `.htaccess` contiene directivas `php_value`; estas requieren `mod_php`. Con PHP-FPM esas directivas no se aplican y deben configurarse en el pool de PHP o en la configuración PHP adecuada.
- Revisa `upload_max_filesize`, `post_max_size`, `max_file_uploads`, `max_execution_time` y `max_input_time` en PHP. El `.htaccess` del proyecto establece valores muy permisivos para las subidas; adáptalos a la memoria, disco y límites reales del servidor.
- Habilita HTTPS antes de permitir acceso público.

### OnlyOffice

La integración espera un OnlyOffice Document Server accesible desde el navegador bajo la ruta `/onlyoffice` y construye URL de retorno a `http://host.docker.internal`. La configuración se encuentra en `office.php` y debe coincidir con la red y el proxy del despliegue.

Para que la edición funcione, OnlyOffice debe poder recuperar el documento desde la aplicación y devolver el guardado a `office_callback.php`. Verifica conectividad en ambas direcciones, el hostname que ve el contenedor, el uso de HTTPS y la configuración de JWT del Document Server si está habilitada. No desactives la validación TLS como solución permanente.

## Puesta en marcha (orientativa)

1. Instala Apache, PHP y MariaDB, además de los módulos/extensiones PHP requeridos.
2. Coloca el código fuera del DocumentRoot si es posible y crea un VirtualHost o alias para la interfaz.
3. Prepara las variables de entorno de la base de datos y una clave maestra segura.
4. Crea los directorios de datos, configura permisos restrictivos para `www-data` y protege los datos frente al acceso directo por URL.
5. Configura HTTPS y, si vas a usar edición de documentos, instala y conecta OnlyOffice Document Server.
6. Abre la aplicación, crea una cuenta y confirma que puede iniciar sesión, subir y recuperar un archivo.
7. Configura copias de seguridad regulares de la base de datos, archivos de usuarios y clave maestra. Prueba la restauración en un entorno separado.

No se incluyen aquí comandos de instalación específicos porque dependen de la distribución, la versión de PHP y cómo se despliegue OnlyOffice.

## Copias de seguridad y recuperación

Para poder restaurar Nube deben guardarse juntos, de forma coherente:

1. La base de datos MariaDB (usuarios, hashes de contraseña, metadatos de cuenta, permisos y claves de datos envueltas).
2. El directorio de archivos de los usuarios.
3. La clave maestra de cifrado.
4. Los archivos de configuración de Apache/PHP y las variables de entorno necesarias.

Si la base de datos y los archivos corresponden a momentos distintos, puede haber referencias incoherentes. Mantén al menos una copia fuera del disco donde vive `/mnt/interno-hdd` y realiza pruebas de recuperación.

## Seguridad antes de publicar en Internet

- Sustituye las credenciales predeterminadas de la base de datos y evita usar `root` desde PHP.
- Saca todos los secretos y claves del código fuente; revísalos antes de subir el proyecto a un repositorio.
- Bloquea el acceso web directo a datos y caché; guarda datos fuera del DocumentRoot siempre que sea viable.
- Limita permisos de archivos y directorios, y evita permisos `0777`.
- Revisa el flujo de registro y la asignación inicial de administradores; desactiva el registro abierto si no lo necesitas.
- Mantén actualizado PHP, Apache, MariaDB y OnlyOffice.
- Usa HTTPS, contraseñas robustas, copias fuera del servidor y registros protegidos.
- Prueba los permisos de lectura/edición, enlaces compartidos y descargas con cuentas distintas antes de guardar datos importantes.

## Limitaciones conocidas del despliegue

- La integración de OnlyOffice depende de direcciones internas específicas del despliegue y puede requerir ajustes si cambia el hostname, contenedor, proxy o dominio.
- Los límites de carga del `.htaccess` dependen del modo de PHP usado por Apache.
- El acceso de la aplicación a los datos depende de que `/mnt/interno-hdd` esté montado con permisos adecuados.
- El código de conexión a MariaDB inicializa esquema automáticamente y no es un instalador de producción con migraciones versionadas.
