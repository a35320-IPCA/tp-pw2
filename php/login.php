<?php
session_start();

if (!empty($_SESSION['authenticated'])) {
  $perfilSessao = strtolower(trim((string)($_SESSION['perfil'] ?? '')));
  if ($perfilSessao === 'aluno') {
    header('Location: aluno.php');
  } elseif ($perfilSessao === 'funcionario') {
    header('Location: matriculas.php');
  } else {
    header('Location: disciplinas.php');
  }
  exit;
}

$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'ipcapw';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die('Falha na ligacao: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
$conn->query('ALTER TABLE users MODIFY pwd VARCHAR(255) NOT NULL');

function e($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validatePasswordAndUpgrade(mysqli $conn, $login, $plainPassword, $storedPassword)
{
  $storedPassword = rtrim((string)$storedPassword);
  if ($storedPassword === '') {
    return false;
  }

  if (password_verify($plainPassword, $storedPassword)) {
    return true;
  }

  if (hash_equals($storedPassword, $plainPassword)) {
    $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $stmtUpdate = $conn->prepare('UPDATE users SET pwd = ? WHERE login = ?');
    if ($stmtUpdate) {
      $stmtUpdate->bind_param('ss', $newHash, $login);
      $stmtUpdate->execute();
      $stmtUpdate->close();
    }
    return true;
  }

  if (preg_match('/^[a-f0-9]{32}$/i', $storedPassword)) {
    return hash_equals(strtolower($storedPassword), md5($plainPassword));
  }

  if (preg_match('/^[a-f0-9]{40}$/i', $storedPassword)) {
    return hash_equals(strtolower($storedPassword), sha1($plainPassword));
  }

  return false;
}

$error = '';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/ipca/php/login.php')), '/');
$loginUrl = $scheme . '://' . $host . $scriptDir . '/login.php';
$qrUrl = 'https://quickchart.io/qr?size=180&text=' . urlencode($loginUrl);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login = trim($_POST['login'] ?? '');
  $pwd = (string)($_POST['pwd'] ?? '');

  if ($login === '' || $pwd === '') {
    $error = 'Preencha login e palavra-passe.';
  } elseif (mb_strlen($login) < 3 || mb_strlen($login) > 50) {
    $error = 'Login invalido.';
  } elseif (preg_match('/^[A-Za-z0-9._-]+$/', $login) !== 1) {
    $error = 'Login invalido.';
  } elseif (strlen($pwd) > 72) {
    $error = 'Palavra-passe invalida.';
  } else {
    $sql = "SELECT u.IdUser, u.login, u.pwd, u.Idperfil, p.perfil
            FROM users u
            LEFT JOIN perfis p ON p.IdPerfil = u.Idperfil
            WHERE LOWER(u.login) = LOWER(?)
            LIMIT 1";
    $stmtUser = $conn->prepare($sql);

    if (!$stmtUser) {
      $error = 'Erro ao validar credenciais.';
    } else {
      $stmtUser->bind_param('s', $login);
      $stmtUser->execute();
      $stmtUser->bind_result($dbIdUser, $dbLogin, $dbPwd, $dbIdPerfil, $dbPerfil);

      $user = null;
      if ($stmtUser->fetch()) {
        $user = [
          'IdUser' => $dbIdUser,
          'login' => $dbLogin,
          'pwd' => $dbPwd,
          'Idperfil' => $dbIdPerfil,
          'perfil' => $dbPerfil,
        ];
      }
      $stmtUser->close();

      if ($user) {
        $storedPwd = (string)$user['pwd'];
        $isValid = validatePasswordAndUpgrade($conn, $login, $pwd, $storedPwd);

        if ($isValid) {
          $_SESSION['authenticated'] = true;
          $_SESSION['iduser'] = (int)$user['IdUser'];
          $_SESSION['login'] = $user['login'];
          $_SESSION['idperfil'] = $user['Idperfil'];
          $_SESSION['perfil'] = $user['perfil'] ?? '';

          $perfilDestino = strtolower(trim((string)($_SESSION['perfil'] ?? '')));
          if ($perfilDestino === 'aluno') {
            header('Location: aluno.php');
          } elseif ($perfilDestino === 'funcionario') {
            header('Location: matriculas.php');
          } else {
            header('Location: disciplinas.php');
          }
          exit;
        }
      }

      $error = 'Login ou palavra-passe invalidos.';
    }
  }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Autenticacao - IPCA</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
</head>
<body class="ipca-auth">
  <div class="card">
    <div class="brand-row">
      <img src="../images/ipcalogo.png" alt="Logotipo IPCA">
      <div class="brand-text">Servicos Academicos e da Gestao Pedagogica</div>
    </div>
    <h1>Iniciar sessão</h1>

    <form method="post">
      <label for="login">Login</label>
      <input id="login" name="login" type="text" autocomplete="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" title="Use 3 a 50 caracteres: letras, numeros, ponto, underscore e hifen." required>

      <label for="pwd">Palavra-passe</label>
      <input id="pwd" name="pwd" type="password" autocomplete="current-password" maxlength="72" required>

      <button type="submit">Entrar</button>
    </form>

    <a class="secondary-btn" href="criar_conta.php">Criar conta</a>
    <button type="button" class="qr-toggle" id="qrToggleBtn">Abrir QR</button>

    <?php if ($error !== ''): ?>
      <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="qr-box" id="qrBox">
      <img src="<?php echo e($qrUrl); ?>" alt="QR code para login">
      <p>Ler QR para abrir esta pagina de login.</p>
    </div>
  </div>

  <script>
    const qrToggleBtn = document.getElementById('qrToggleBtn');
    const qrBox = document.getElementById('qrBox');

    if (qrToggleBtn && qrBox) {
      qrToggleBtn.addEventListener('click', function () {
        const isOpen = qrBox.style.display === 'block';
        qrBox.style.display = isOpen ? 'none' : 'block';
        qrToggleBtn.textContent = isOpen ? 'Abrir QR' : 'Fechar QR';
      });
    }
  </script>
</body>
</html>
