<?php
session_start();

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ipcapw";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die('Falha na ligação: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
$conn->query("ALTER TABLE users MODIFY pwd VARCHAR(255) NOT NULL");


function e($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$perfis = [];
$resultPerfis = $conn->query("SELECT IdPerfil, perfil FROM perfis ORDER BY perfil");
if ($resultPerfis) {
  while ($row = $resultPerfis->fetch_assoc()) {
    $perfis[] = $row;
  }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login = trim($_POST['login'] ?? '');
  $pwd = (string)($_POST['pwd'] ?? '');
  $idPerfil = (int)($_POST['Idperfil'] ?? 0);

  if ($login === '' || $pwd === '' || $idPerfil <= 0) {
    $error = 'Preencha login, password e perfil.';
  } elseif (mb_strlen($login) < 3 || mb_strlen($login) > 50) {
    $error = 'O login deve ter entre 3 e 50 caracteres.';
  } elseif (preg_match('/^[A-Za-z0-9._-]+$/', $login) !== 1) {
    $error = 'O login só pode conter letras, números, ponto, underscore e hífen.';
  } elseif (strlen($pwd) > 72) {
    $error = 'A password não pode exceder 72 caracteres.';
  } elseif (strlen($pwd) < 8) {
    $error = 'A password deve ter pelo menos 8 caracteres.';
  } elseif (preg_match('/[A-Za-z]/', $pwd) !== 1 || preg_match('/\d/', $pwd) !== 1) {
    $error = 'A password deve incluir pelo menos uma letra e um número.';
  } else {
    $stmtPerfil = $conn->prepare("SELECT IdPerfil, perfil FROM perfis WHERE IdPerfil = ? LIMIT 1");
    $perfilExiste = false;
    $perfilNome = '';
    if ($stmtPerfil) {
      $stmtPerfil->bind_param('i', $idPerfil);
      $stmtPerfil->execute();
      $resultPerfil = $stmtPerfil->get_result();
      $perfilRow = $resultPerfil->fetch_assoc();
      if ($perfilRow) {
        $perfilExiste = true;
        $perfilNome = strtolower(trim((string)$perfilRow['perfil']));
      }
      $stmtPerfil->close();
    }

    if (!$perfilExiste) {
      $error = 'Perfil inválido.';
    } elseif (strpos($perfilNome, 'gestor') !== false) {
      $error = 'Não é permitido criar conta com perfil de gestor.';
    } else {
      $stmtCheck = $conn->prepare("SELECT 1 FROM `users` WHERE LOWER(login) = LOWER(?) LIMIT 1");
      if (!$stmtCheck) {
        $error = 'Erro ao validar utilizador.';
      } else {
        $stmtCheck->bind_param('s', $login);
        $stmtCheck->execute();
        $stmtCheck->store_result();
        $alreadyExists = $stmtCheck->num_rows > 0;
        $stmtCheck->close();

        if ($alreadyExists) {
          $error = 'Esse nome de utilizador já existe.';
        } else {
          $hashedPwd = password_hash($pwd, PASSWORD_DEFAULT);
          $stmtInsert = $conn->prepare("INSERT INTO `users` (login, pwd, Idperfil) VALUES (?, ?, ?)");

          if (!$stmtInsert) {
            $error = 'Não foi possível criar a conta.';
          } else {
            $stmtInsert->bind_param('ssi', $login, $hashedPwd, $idPerfil);
            $ok = $stmtInsert->execute();
            $newUserId = (int)$stmtInsert->insert_id;
            $stmtInsert->close();

            if ($ok) {
              if ($perfilNome === 'aluno') {
                $_SESSION['authenticated'] = true;
                $_SESSION['iduser'] = $newUserId;
                $_SESSION['login'] = $login;
                $_SESSION['idperfil'] = $idPerfil;
                $_SESSION['perfil'] = 'aluno';
                header('Location: ficha_aluno.php?from=signup');
                exit;
              }

              $success = 'Conta criada com sucesso. Já podes iniciar sessão.';
            } else {
              $error = 'Não foi possível criar a conta nesta base de dados.';
            }
          }
        }
      }
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
  <title>Criar conta - IPCA</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
</head>
<body class="ipca-auth">
  <div class="card">
    <div class="brand-row">
      <img src="../images/ipcalogo.png" alt="Logotipo IPCA">
      <div class="brand-text">Registo de utilizador</div>
    </div>
    <h1>Criar conta</h1>
    <form method="post">
      <label for="login">Login</label>
      <input id="login" name="login" type="text" autocomplete="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" title="Use 3 a 50 caracteres: letras, números, ponto, underscore e hífen." required value="<?php echo e($_POST['login'] ?? ''); ?>">

      <label for="Idperfil">Perfil</label>
      <select id="Idperfil" name="Idperfil" required>
        <option value="">Selecione</option>
        <?php foreach ($perfis as $perfil): ?>
          <?php $perfilNomeOpcao = strtolower(trim((string)($perfil['perfil'] ?? ''))); ?>
          <?php if (strpos($perfilNomeOpcao, 'gestor') !== false) { continue; } ?>
          <option value="<?php echo e($perfil['IdPerfil']); ?>" <?php echo ((int)($_POST['Idperfil'] ?? 0) === (int)$perfil['IdPerfil']) ? 'selected' : ''; ?>>
            <?php echo e($perfil['perfil']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label for="pwd">Password</label>
      <input id="pwd" name="pwd" type="password" autocomplete="new-password" minlength="8" maxlength="72" pattern="(?=.*[A-Za-z])(?=.*\d).{8,72}" title="Mínimo 8 caracteres, com pelo menos uma letra e um número." required>

      <button type="submit">Criar conta</button>
    </form>

    <a class="back-link" href="login.php">Voltar ao login</a>

    <?php if ($success !== ''): ?>
      <div class="success"><?php echo e($success); ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>
  </div>
</body>
</html>

