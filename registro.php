<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

if (usuarioActual() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
$usuario = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = (string) ($_POST['usuario'] ?? '');
    $email   = (string) ($_POST['email'] ?? '');
    $pass    = (string) ($_POST['pass'] ?? '');
    $pass2   = (string) ($_POST['pass2'] ?? '');

    if ($error !== '') {
        // sin cambios
    } elseif (!csrfOk($_POST['csrf'] ?? null)) {
        $error = 'La sesión caducó. Vuelve a intentarlo.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $r = registrar($usuario, $email, $pass);
        if ($r['ok']) {
            entrar($usuario, $pass);
            header('Location: index.php');
            exit;
        }
        $error = (string) ($r['error'] ?? 'No se pudo crear la cuenta.');
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Crear cuenta · Nube</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path fill='%231a73e8' d='M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z'/></svg>">
<script>(function(){var t;try{t=localStorage.getItem('nubeTema');}catch(e){t=null;}document.documentElement.setAttribute('data-tema', t==='oscuro'?'oscuro':'claro');})();</script>
<style>
    * { box-sizing: border-box; }
    :root { --bg:#f1f3f4; --card:#fff; --txt:#202124; --txt2:#5f6368; --borde:#dadce0; --azul:#1a73e8; --azul2:#1765cc; --input:#fff; --err:#d93025; }
    :root[data-tema="oscuro"] { --bg:#1b1c1f; --card:#26282c; --txt:#e8eaed; --txt2:#c9cdd2; --borde:#3a3d41; --input:#1f2124; color-scheme:dark; }
    body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:'Segoe UI',Roboto,Arial,sans-serif; background:var(--bg); color:var(--txt); padding:24px; }
    .tarjeta { width:100%; max-width:400px; background:var(--card); border:1px solid var(--borde); border-radius:16px; padding:32px; box-shadow:0 18px 50px -28px rgba(0,0,0,.55); }
    .logo { display:flex; align-items:center; justify-content:center; gap:10px; font-size:1.55rem; font-weight:700; margin-bottom:6px; }
    .logo svg { width:34px; height:34px; }
    .sub { text-align:center; color:var(--txt2); font-size:.9rem; margin-bottom:24px; }
    label { display:block; font-size:.82rem; font-weight:600; color:var(--txt2); margin-bottom:6px; }
    input { width:100%; padding:11px 13px; border:1px solid var(--borde); border-radius:8px; background:var(--input); color:var(--txt); font-size:.95rem; margin-bottom:16px; outline:none; }
    input:focus { border-color:var(--azul); box-shadow:0 0 0 3px rgba(26,115,232,.18); }
    .btn { width:100%; border:none; border-radius:8px; padding:12px; font-size:.95rem; font-weight:700; cursor:pointer; background:var(--azul); color:#fff; }
    .btn:hover { background:var(--azul2); }
    .pie { text-align:center; margin-top:18px; font-size:.88rem; color:var(--txt2); }
    .pie a { color:var(--azul); text-decoration:none; font-weight:600; }
    .msg { padding:10px 14px; border-radius:8px; font-size:.85rem; margin-bottom:16px; background:rgba(217,48,37,.12); color:var(--err); border:1px solid rgba(217,48,37,.3); }
    .pista { font-size:.74rem; color:var(--txt2); margin:-10px 0 14px; }
    .theme { position:fixed; top:16px; right:16px; width:44px; height:44px; border:none; background:transparent; font-size:1.3rem; cursor:pointer; border-radius:50%; color:var(--txt2); }
    .theme:hover { background:var(--card); }
</style>
</head>
<body>
<button class="theme" id="btnTema" type="button" title="Cambiar tema">🌙</button>
<div class="tarjeta">
    <div class="logo">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.2 19.5h9.6a4.3 4.3 0 0 0 .5-8.57A5.8 5.8 0 0 0 6.8 9.3 4.4 4.4 0 0 0 7.2 19.5z" fill="#1a73e8"/><path d="M12 16.4V9.9m-2.6 2.6L12 9.9l2.6 2.6" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>Nube</span>
    </div>
    <div class="sub">Crea tu cuenta y tendrás tu propio espacio</div>

    <?php if ($error !== ''): ?><div class="msg"><?= h($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <label for="usuario">Usuario</label>
        <input type="text" id="usuario" name="usuario" required autofocus value="<?= h($usuario) ?>">
        <div class="pista">3-30 caracteres: letras, números, punto, guion o guion bajo.</div>
        <label for="email">Correo electrónico</label>
        <input type="email" id="email" name="email" required value="<?= h($email) ?>">
        <label for="pass">Contraseña</label>
        <input type="password" id="pass" name="pass" required minlength="6">
        <label for="pass2">Repite la contraseña</label>
        <input type="password" id="pass2" name="pass2" required minlength="6">
        <button class="btn" type="submit">Crear cuenta</button>
    </form>

    <div class="pie">¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a></div>
</div>
<script>
const b=document.getElementById('btnTema');
function p(){const o=document.documentElement.getAttribute('data-tema')==='oscuro';b.textContent=o?'☀️':'🌙';}
b.addEventListener('click',()=>{const o=document.documentElement.getAttribute('data-tema')==='oscuro';const n=o?'claro':'oscuro';document.documentElement.setAttribute('data-tema',n);try{localStorage.setItem('nubeTema',n);}catch(e){}p();});
p();
</script>
</body>
</html>
