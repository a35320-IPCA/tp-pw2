<?php
session_start();

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
  header('Location: login.php');
  exit;
}

if (empty($_SESSION['authenticated'])) {
  header('Location: login.php');
  exit;
}

$perfilAtual = strtolower(trim((string)($_SESSION['perfil'] ?? '')));
if ($perfilAtual !== 'aluno') {
  if ($perfilAtual === 'funcionario') {
    header('Location: matriculas.php');
  } else {
    header('Location: disciplinas.php');
  }
  exit;
}

$conn = new mysqli('localhost', 'root', '', 'ipcapw');
if ($conn->connect_error) {
  die('Falha na ligacao a base de dados: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
$conn->query("ALTER TABLE ficha_aluno MODIFY Status VARCHAR(50) NOT NULL DEFAULT 'Rascunho'");

function e($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage($type, $message)
{
  $type = urlencode($type);
  $message = urlencode($message);
  header("Location: ficha_aluno.php?type={$type}&message={$message}");
  exit;
}

function getUploadedImageBlob($fieldName, &$errorMessage)
{
  $errorMessage = '';

  if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
    return null;
  }

  if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
    $errorMessage = 'Erro no carregamento da foto.';
    return false;
  }

  $tmpFile = $_FILES[$fieldName]['tmp_name'];
  if (@getimagesize($tmpFile) === false) {
    $errorMessage = 'O ficheiro enviado nao e uma imagem valida.';
    return false;
  }

  $imageData = file_get_contents($tmpFile);
  if ($imageData === false) {
    $errorMessage = 'Nao foi possivel ler a imagem enviada.';
    return false;
  }

  if (strlen($imageData) > 5 * 1024 * 1024) {
    $errorMessage = 'A imagem excede 5 MB.';
    return false;
  }

  return $imageData;
}

$idUserSessao = (int)($_SESSION['iduser'] ?? 0);
if ($idUserSessao <= 0) {
  $loginSessao = (string)($_SESSION['login'] ?? '');
  $stmtSessao = $conn->prepare('SELECT IdUser FROM users WHERE LOWER(login) = LOWER(?) LIMIT 1');
  if ($stmtSessao) {
    $stmtSessao->bind_param('s', $loginSessao);
    $stmtSessao->execute();
    $rowSessao = $stmtSessao->get_result()->fetch_assoc();
    $stmtSessao->close();
    $idUserSessao = (int)($rowSessao['IdUser'] ?? 0);
    if ($idUserSessao > 0) {
      $_SESSION['iduser'] = $idUserSessao;
    }
  }
}

if ($idUserSessao <= 0) {
  header('Location: login.php');
  exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'foto') {
  $stmtFoto = $conn->prepare('SELECT foto FROM ficha_aluno WHERE IdUser = ? LIMIT 1');
  if (!$stmtFoto) {
    http_response_code(404);
    exit;
  }
  $stmtFoto->bind_param('i', $idUserSessao);
  $stmtFoto->execute();
  $fotoRow = $stmtFoto->get_result()->fetch_assoc();
  $stmtFoto->close();

  if (!$fotoRow || $fotoRow['foto'] === null) {
    http_response_code(404);
    exit;
  }

  header('Content-Type: image/jpeg');
  echo $fotoRow['foto'];
  exit;
}

$stmtFicha = $conn->prepare('SELECT * FROM ficha_aluno WHERE IdUser = ? LIMIT 1');
if (!$stmtFicha) {
  die('Nao foi possivel obter a ficha do aluno.');
}
$stmtFicha->bind_param('i', $idUserSessao);
$stmtFicha->execute();
$fichaAtual = $stmtFicha->get_result()->fetch_assoc();
$stmtFicha->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nome = trim((string)($_POST['nome'] ?? ''));
  $idade = (int)($_POST['idade'] ?? 0);
  $telefone = trim((string)($_POST['telefone'] ?? ''));
  $morada = trim((string)($_POST['morada'] ?? ''));
  $nif = trim((string)($_POST['nif'] ?? ''));
  $dataNascimento = trim((string)($_POST['data_nascimento'] ?? ''));

  if ($nome === '' || $idade <= 0 || $telefone === '' || $morada === '' || $nif === '' || $dataNascimento === '') {
    redirectWithMessage('error', 'Preenche todos os campos obrigatorios.');
  }
  if (!preg_match('/^[0-9]{9,15}$/', $telefone)) {
    redirectWithMessage('error', 'Telefone invalido. Usa apenas digitos (9 a 15).');
  }
  if (!preg_match('/^[0-9]{9}$/', $nif)) {
    redirectWithMessage('error', 'NIF invalido. Deve ter 9 digitos.');
  }
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataNascimento) || strtotime($dataNascimento) === false) {
    redirectWithMessage('error', 'Data de nascimento invalida.');
  }

  $uploadError = '';
  $fotoBlob = getUploadedImageBlob('foto', $uploadError);
  if ($fotoBlob === false) {
    redirectWithMessage('error', $uploadError);
  }

  $statusFicha = 'Rascunho';

  if ($fichaAtual) {
    if ($fotoBlob !== null) {
      $stmtSave = $conn->prepare('UPDATE ficha_aluno SET nome = ?, idade = ?, telefone = ?, morada = ?, nif = ?, data_nascimento = ?, foto = ?, Status = ? WHERE IdUser = ?');
      if (!$stmtSave) {
        redirectWithMessage('error', 'Nao foi possivel atualizar a ficha.');
      }
      $stmtSave->bind_param('sissssssi', $nome, $idade, $telefone, $morada, $nif, $dataNascimento, $fotoBlob, $statusFicha, $idUserSessao);
    } else {
      $stmtSave = $conn->prepare('UPDATE ficha_aluno SET nome = ?, idade = ?, telefone = ?, morada = ?, nif = ?, data_nascimento = ?, Status = ? WHERE IdUser = ?');
      if (!$stmtSave) {
        redirectWithMessage('error', 'Nao foi possivel atualizar a ficha.');
      }
      $stmtSave->bind_param('sisssssi', $nome, $idade, $telefone, $morada, $nif, $dataNascimento, $statusFicha, $idUserSessao);
    }

    $ok = $stmtSave->execute();
    $stmtSave->close();
    if (!$ok) {
      redirectWithMessage('error', 'Nao foi possivel atualizar a ficha de aluno.');
    }
  } else {
    if ($fotoBlob === null) {
      redirectWithMessage('error', 'A foto e obrigatoria no primeiro envio da ficha.');
    }

    $stmtSave = $conn->prepare('INSERT INTO ficha_aluno (nome, idade, telefone, morada, nif, data_nascimento, foto, IdUser, Status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmtSave) {
      redirectWithMessage('error', 'Nao foi possivel guardar a ficha.');
    }
    $stmtSave->bind_param('sisssssis', $nome, $idade, $telefone, $morada, $nif, $dataNascimento, $fotoBlob, $idUserSessao, $statusFicha);
    $ok = $stmtSave->execute();
    $stmtSave->close();
    if (!$ok) {
      redirectWithMessage('error', 'Nao foi possivel guardar a ficha de aluno.');
    }
  }

  header('Location: aluno.php?type=success&message=' . urlencode('Ficha guardada com sucesso. Estado definido como Rascunho.'));
  exit;
}

$message = (string)($_GET['message'] ?? '');
$type = (string)($_GET['type'] ?? '');
$statusAtual = trim((string)($fichaAtual['Status'] ?? 'Sem ficha'));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ficha de Aluno - IPCA</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1>Ficha de Aluno</h1>
      <p>Ao guardar a ficha, o estado fica em Rascunho ate submeteres na area do aluno.</p>
    </header>

    <nav>
      <a href="aluno.php">Minha Area</a>
      <a href="aluno_notas.php">Minhas Notas</a>
      <a href="?action=logout">Terminar sessao</a>
    </nav>

    <?php if ($message !== ''): ?>
      <div class="message <?php echo e($type); ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="form-box">
      <p><strong>Estado atual da ficha:</strong> <?php echo e($statusAtual); ?></p>

      <form method="post" enctype="multipart/form-data">
        <?php if ($fichaAtual && !empty($fichaAtual['foto'])): ?>
          <img class="photo-preview" src="ficha_aluno.php?action=foto" alt="Foto do aluno">
        <?php endif; ?>

        <label for="nome">Nome</label>
        <input id="nome" name="nome" type="text" required value="<?php echo e($fichaAtual['nome'] ?? ''); ?>">

        <label for="idade">Idade</label>
        <input id="idade" name="idade" type="number" min="1" required value="<?php echo e($fichaAtual['idade'] ?? ''); ?>">

        <label for="telefone">Telefone</label>
        <input id="telefone" name="telefone" type="tel" required value="<?php echo e($fichaAtual['telefone'] ?? ''); ?>">

        <label for="morada">Morada</label>
        <input id="morada" name="morada" type="text" required value="<?php echo e($fichaAtual['morada'] ?? ''); ?>">

        <label for="nif">NIF</label>
        <input id="nif" name="nif" type="text" inputmode="numeric" maxlength="9" required value="<?php echo e($fichaAtual['nif'] ?? ''); ?>">

        <label for="data_nascimento">Data de nascimento</label>
        <input id="data_nascimento" name="data_nascimento" type="date" required value="<?php echo e($fichaAtual['data_nascimento'] ?? ''); ?>">

        <label for="foto">Foto</label>
        <input id="foto" name="foto" type="file" accept="image/*" <?php echo $fichaAtual ? '' : 'required'; ?>>

        <button type="submit"><?php echo $fichaAtual ? 'Guardar ficha' : 'Criar ficha'; ?></button>
      </form>
    </div>
  </div>
</body>
</html>
<?php $conn->close(); ?>
