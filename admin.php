<?php
declare(strict_types=1);
require_once __DIR__ . '/fs.php';

$admin = requiereAdmin();

$error = '';
$aviso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk($_POST['csrf'] ?? null)) {
        $error = 'La sesión caducó. Vuelve a intentarlo.';
    } else {
        $accion = (string) ($_POST['accion'] ?? '');
        if ($accion === 'password') {
            $uid    = (int) ($_POST['uid'] ?? 0);
            $nueva  = (string) ($_POST['nueva'] ?? '');
            $repite = (string) ($_POST['repite'] ?? '');
            $st = bd()->prepare('SELECT usuario FROM usuarios WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $objetivo = $st->fetch();
            if (!$objetivo) {
                $error = 'El usuario no existe.';
            } elseif ($nueva !== $repite) {
                $error = 'Las dos contraseñas no coinciden.';
            } else {
                $r = cambiarPassword($uid, $nueva, null, true);
                if (!empty($r['ok'])) {
                    $aviso = 'Contraseña de «' . (string) $objetivo['usuario'] . '» actualizada.';
                } else {
                    $error = (string) ($r['error'] ?? 'No se pudo cambiar la contraseña.');
                }
            }
        }
    }
}

$usuarios = bd()->query('SELECT id, usuario, email, creado_en, es_admin FROM usuarios ORDER BY es_admin DESC, usuario ASC')->fetchAll();
$conteo = count($usuarios);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usuarios · Nube</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='%231a73e8' d='M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'/></svg>">
<style>
* { box-sizing: border-box; }
:root { --bg:#f1f3f4; --card:#fff; --txt:#202124; --txt2:#5f6368; --borde:#dadce0; --azul:#1a73e8; --err:#d93025; }
:root[data-tema="oscuro"] { --bg:#1b1c1f; --card:#26282c; --txt:#e8eaed; --txt2:#c9cdd2; --borde:#3a3d41; color-scheme:dark; }
body { margin:0; font-family:system-ui,sans-serif; background:var(--bg); color:var(--txt); display:flex; justify-content:center; padding:32px 16px; }
.tarjeta { background:var(--card); border:1px solid var(--borde); border-radius:14px; padding:26px; width:min(760px,100%); }
h1 { margin:0 0 6px; font-size:1.3rem; }
.sub { color:var(--txt2); font-size:.9rem; margin-bottom:18px; }
table { width:100%; border-collapse:collapse; font-size:.9rem; }
th, td { text-align:left; padding:9px 8px; border-bottom:1px solid var(--borde); vertical-align:middle; }
th { color:var(--txt2); font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.4px; }
.badge { display:inline-block; padding:2px 8px; border-radius:99px; font-size:.72rem; font-weight:700; background:#e8f0fe; color:var(--azul); }
.badge.admin { background:#e6f4ea; color:#188038; }
input[type=password] { padding:6px 8px; border:1px solid var(--borde); border-radius:6px; font-size:.85rem; background:var(--card); color:var(--txt); width:130px; }
button { padding:7px 12px; border:0; border-radius:6px; background:var(--azul); color:#fff; font-size:.82rem; cursor:pointer; }
.error { margin-bottom:10px; padding:8px 10px; background:#fdecea; color:var(--err); border-radius:8px; font-size:.85rem; }
.aviso { margin-bottom:10px; padding:8px 10px; background:#e6f4ea; color:#188038; border-radius:8px; font-size:.85rem; }
.muted { color:var(--txt2); font-size:.82rem; }
a.volver { display:inline-block; margin-top:16px; color:var(--azul); text-decoration:none; font-size:.9rem; }
details { margin-top:18px; border-top:1px solid var(--borde); padding-top:14px; }
summary { cursor:pointer; font-weight:600; font-size:.92rem; }
.oculto { display:none; }
</style>
</head>
<body>
<div class="tarjeta">
  <h1>Usuarios de la instalación</h1>
  <div class="sub">Administración · <?= h((string) $admin['usuario']) ?></div>

  <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($aviso !== ''): ?><div class="aviso"><?= h($aviso) ?></div><?php endif; ?>

  <table>
    <thead>
      <tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Contraseña</th></tr>
    </thead>
    <tbody>
      <?php foreach ($usuarios as $u): ?>
      <tr>
        <td><?= h((string) $u['usuario']) ?></td>
        <td class="muted"><?= h((string) $u['email']) ?></td>
        <td><?php if (!empty($u['es_admin'])): ?><span class="badge admin">Admin</span><?php else: ?><span class="badge">Usuario</span><?php endif; ?></td>
        <td>
          <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="accion" value="password">
            <input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
            <input type="password" name="nueva" placeholder="Nueva" required minlength="6">
            <input type="password" name="repite" placeholder="Repetir" required minlength="6">
            <button type="submit" onclick="return confirm('¿Cambiar la contraseña de «<?= h((string) $u['usuario']) ?>»?')">Cambiar</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="muted" style="margin-top:10px"><?= $conteo ?> usuario<?= $conteo === 1 ? '' : 's' ?> en total.</div>

  <div class="muted" style="margin-top:14px">Aquí el administrador puede restablecer la contraseña de cualquier cuenta. Los usuarios nuevos se crean desde la pantalla de <a href="registro.php">registro</a>.</div>

  <a class="volver" href="index.php">← Volver a la nube</a>
</div>
</body>
</html>
