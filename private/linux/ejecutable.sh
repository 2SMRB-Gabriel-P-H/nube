#!/usr/bin/env bash
# Genera una clave maestra única para esta instalación. No sobrescribe claves.
set -euo pipefail

DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
KEY="$DIR/../nube_master.key"

if [ -e "$KEY" ]; then
    echo "Ya existe una clave en esta instalación; no se ha cambiado."
    exit 1
fi
if ! command -v php >/dev/null 2>&1; then
    echo "Error: se necesita PHP CLI para generar la clave." >&2
    exit 1
fi

umask 077
TMP="$(mktemp "$DIR/.nube_master.XXXXXX")"
trap 'rm -f -- "$TMP"' EXIT
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > "$TMP"
chmod 0600 "$TMP"
if ! ln -- "$TMP" "$KEY" 2>/dev/null; then
    echo "No se pudo crear la clave (quizá ya existe). No se sobrescribió ningún archivo." >&2
    exit 1
fi
rm -f -- "$TMP"
trap - EXIT

ACL_TOOL="$(command -v setfacl || true)"
WEB_USERS=()
for user in www-data apache httpd; do
    if id "$user" >/dev/null 2>&1; then WEB_USERS+=("$user"); fi
done
if [ -n "$ACL_TOOL" ]; then
    for user in "${WEB_USERS[@]}"; do
        "$ACL_TOOL" -m "u:$user:--x" "$(dirname -- "$KEY")"
        "$ACL_TOOL" -m "u:$user:r--" "$KEY"
    done
elif [ "${#WEB_USERS[@]}" -gt 0 ]; then
    echo "Aviso: falta setfacl. El servidor web podría no poder leer la clave." >&2
    echo "Instala ACL o concede lectura a la cuenta del servicio web de forma segura." >&2
fi

echo "Clave creada correctamente."
