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
$isAluno = $perfilAtual === 'aluno';
$isFuncionario = $perfilAtual === 'funcionario';

if (!$isAluno) {
  if ($isFuncionario) {
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

function fotoDataUrlFromBlob($blob)
{
  if ($blob === null || $blob === '') {
    return '';
  }

  $bin = (string)$blob;
  $mime = 'application/octet-stream';
  $info = @getimagesizefromstring($bin);
  if (is_array($info) && !empty($info['mime'])) {
    $mime = (string)$info['mime'];
  } elseif (strncmp($bin, "\xFF\xD8\xFF", 3) === 0) {
    $mime = 'image/jpeg';
  } elseif (strncmp($bin, "\x89PNG", 4) === 0) {
    $mime = 'image/png';
  } elseif (strncmp($bin, 'GIF8', 4) === 0) {
    $mime = 'image/gif';
  } elseif (strncmp($bin, 'RIFF', 4) === 0 && substr($bin, 8, 4) === 'WEBP') {
    $mime = 'image/webp';
  }

  if (strpos($mime, 'image/') !== 0) {
    return '';
  }

  return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

function redirectWithMessage($type, $message)
{
  $type = urlencode($type);
  $message = urlencode($message);
  header("Location: aluno.php?type={$type}&message={$message}");
  exit;
}

function getUploadedImageBlob($fieldName, &$errorMessage)
{
  $errorMessage = '';

  if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
    $errorMessage = 'Erro no upload do ficheiro.';
    return false;
  }

  $tmpFile = $_FILES[$fieldName]['tmp_name'];

  $imageData = file_get_contents($tmpFile);
  if ($imageData === false) {
    $errorMessage = 'Nao foi possivel ler o ficheiro enviado.';
    return false;
  }

  $mimeUpload = 'application/octet-stream';
  if (strncmp($imageData, '%PDF-', 5) === 0) {
    $mimeUpload = 'application/pdf';
  } else {
    $imageInfo = @getimagesizefromstring($imageData);
    if (is_array($imageInfo) && !empty($imageInfo['mime'])) {
      $mimeUpload = (string)$imageInfo['mime'];
    }
    if ($mimeUpload === 'application/octet-stream' && strncmp($imageData, 'RIFF', 4) === 0 && substr($imageData, 8, 4) === 'WEBP') {
      $mimeUpload = 'image/webp';
    }
  }

  $mimesPermitidos = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];
  if (!in_array($mimeUpload, $mimesPermitidos, true)) {
    $errorMessage = 'Formato invalido. Envia PDF, PNG, JPG/JPEG ou WEBP.';
    return false;
  }

  if (strlen($imageData) > 5 * 1024 * 1024) {
    $errorMessage = 'O ficheiro excede o tamanho maximo permitido (5 MB).';
    return false;
  }

  return $imageData;
}

$loginSessao = (string)($_SESSION['login'] ?? '');
$stmtUser = $conn->prepare('SELECT IdUser, login FROM users WHERE LOWER(login) = LOWER(?) LIMIT 1');
if (!$stmtUser) {
  redirectWithMessage('error', 'Nao foi possivel obter os dados do utilizador.');
}
$stmtUser->bind_param('s', $loginSessao);
$stmtUser->execute();
$userSessao = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if (!$userSessao) {
  redirectWithMessage('error', 'Utilizador nao encontrado.');
}

$idAluno = (int)$userSessao['IdUser'];

$stmtFicha = $conn->prepare('SELECT * FROM ficha_aluno WHERE IdUser = ? LIMIT 1');
$stmtFicha->bind_param('i', $idAluno);
$stmtFicha->execute();
$fichaAluno = $stmtFicha->get_result()->fetch_assoc();
$stmtFicha->close();

if (isset($_GET['action']) && $_GET['action'] === 'foto_ficha') {
  if (!$fichaAluno || $fichaAluno['foto'] === null) {
    http_response_code(404);
    exit;
  }
  $fotoBin = $fichaAluno['foto'];
  $mimeFoto = 'application/octet-stream';
  $infoFoto = @getimagesizefromstring($fotoBin);
  if (is_array($infoFoto) && !empty($infoFoto['mime'])) {
    $mimeFoto = (string)$infoFoto['mime'];
  }

  header('Content-Type: ' . $mimeFoto);
  echo $fotoBin;
  exit;
}

$statusFichaRaw = trim((string)($fichaAluno['Status'] ?? ''));
$statusFicha = strtolower($statusFichaRaw);
$temFicha = (bool)$fichaAluno;
$podeMatricular = $temFicha && $statusFicha === 'aprovada';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postAction = (string)($_POST['action'] ?? '');

  if ($postAction === 'submit_ficha') {
    if (!$temFicha) {
      redirectWithMessage('error', 'Primeiro tens de criar a ficha de aluno.');
    }

    $statusAtualFicha = strtolower(trim((string)($fichaAluno['Status'] ?? '')));
    if (!in_array($statusAtualFicha, ['rascunho', 'rejeitada'], true)) {
      redirectWithMessage('error', 'A ficha so pode ser submetida quando estiver em Rascunho ou Rejeitada.');
    }

    $statusSubmetida = 'Submetida';
    $stmtSubmeter = $conn->prepare('UPDATE ficha_aluno SET Status = ? WHERE IdUser = ?');
    if (!$stmtSubmeter) {
      redirectWithMessage('error', 'Nao foi possivel submeter a ficha.');
    }
    $stmtSubmeter->bind_param('si', $statusSubmetida, $idAluno);
    $okSubmeter = $stmtSubmeter->execute();
    $stmtSubmeter->close();

    redirectWithMessage($okSubmeter ? 'success' : 'error', $okSubmeter ? 'Ficha submetida com sucesso.' : 'Falha ao submeter ficha.');
  }

  if ($postAction !== 'request_enrollment') {
    redirectWithMessage('error', 'Acao invalida.');
  }

  if (!$podeMatricular) {
    redirectWithMessage('error', 'So podes enviar matricula quando a ficha estiver Aprovada.');
  }

  $idCursoPedido = (int)($_POST['IdCurso'] ?? 0);
  if ($idCursoPedido <= 0) {
    redirectWithMessage('error', 'Seleciona um curso valido.');
  }

  $stmtCurso = $conn->prepare('SELECT IdCurso FROM cursos WHERE IdCurso = ? LIMIT 1');
  if (!$stmtCurso) {
    redirectWithMessage('error', 'Nao foi possivel validar o curso.');
  }
  $stmtCurso->bind_param('i', $idCursoPedido);
  $stmtCurso->execute();
  $cursoExiste = $stmtCurso->get_result()->fetch_assoc();
  $stmtCurso->close();

  if (!$cursoExiste) {
    redirectWithMessage('error', 'O curso selecionado nao existe.');
  }

  $uploadError = '';
  $fotoComprovativo = getUploadedImageBlob('Foto', $uploadError);
  if ($fotoComprovativo === false) {
    redirectWithMessage('error', $uploadError);
  }
  if ($fotoComprovativo === null && !empty($fichaAluno['foto'])) {
    $fotoComprovativo = $fichaAluno['foto'];
  }
  if ($fotoComprovativo === null) {
    redirectWithMessage('error', 'E obrigatorio enviar comprovativo com foto.');
  }

  $nomeAluno = (string)$fichaAluno['nome'];

  $stmtExiste = $conn->prepare('SELECT Status FROM matriculas WHERE IdAluno = ? LIMIT 1');
  if (!$stmtExiste) {
    redirectWithMessage('error', 'Nao foi possivel validar matricula existente.');
  }
  $stmtExiste->bind_param('i', $idAluno);
  $stmtExiste->execute();
  $matriculaExistente = $stmtExiste->get_result()->fetch_assoc();
  $stmtExiste->close();

  if ($matriculaExistente) {
    $statusMatricula = strtolower(trim((string)($matriculaExistente['Status'] ?? '')));
    if ($statusMatricula === 'aceite') {
      redirectWithMessage('error', 'Ja tens uma matricula aceite.');
    }
    if ($statusMatricula === 'pendente') {
      redirectWithMessage('error', 'Ja tens um pedido de matricula pendente.');
    }

    $novoStatus = 'Pendente';
    $stmtUp = $conn->prepare('UPDATE matriculas SET Nome = ?, IdCurso = ?, Foto = ?, Status = ?, IdFuncionario = NULL, Data = CURDATE() WHERE IdAluno = ?');
    if (!$stmtUp) {
      redirectWithMessage('error', 'Nao foi possivel reenviar o pedido de matricula.');
    }
    $stmtUp->bind_param('sibsi', $nomeAluno, $idCursoPedido, $fotoComprovativo, $novoStatus, $idAluno);
    $stmtUp->send_long_data(2, $fotoComprovativo);
    $ok = $stmtUp->execute();
    $stmtUp->close();

    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Pedido de matricula enviado com sucesso.' : 'Falha ao enviar pedido de matricula.');
  }

  $statusPendente = 'Pendente';
  $stmtIn = $conn->prepare('INSERT INTO matriculas (IdAluno, Nome, IdCurso, Foto, Status, Data, IdFuncionario) VALUES (?, ?, ?, ?, ?, CURDATE(), NULL)');
  if (!$stmtIn) {
    redirectWithMessage('error', 'Nao foi possivel criar o pedido de matricula.');
  }
  $stmtIn->bind_param('isibs', $idAluno, $nomeAluno, $idCursoPedido, $fotoComprovativo, $statusPendente);
  $stmtIn->send_long_data(3, $fotoComprovativo);
  $ok = $stmtIn->execute();
  $stmtIn->close();

  redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Pedido de matricula enviado com sucesso.' : 'Falha ao enviar pedido de matricula.');
}

$message = (string)($_GET['message'] ?? '');
$type = (string)($_GET['type'] ?? '');

$matriculaAceite = null;
$disciplinasCurso = [];
$pedidosPendentes = [];

$stmtAceite = $conn->prepare("SELECT m.IdCurso, m.Status, c.Curso
  FROM matriculas m
  JOIN cursos c ON c.IdCurso = m.IdCurso
  WHERE m.IdAluno = ? AND LOWER(COALESCE(m.Status, '')) = 'aceite'
  LIMIT 1");
$stmtAceite->bind_param('i', $idAluno);
$stmtAceite->execute();
$matriculaAceite = $stmtAceite->get_result()->fetch_assoc();
$stmtAceite->close();

if ($matriculaAceite) {
  $stmtDisc = $conn->prepare('SELECT d.Disciplina FROM plano_estudos pe JOIN disciplina d ON d.IdDisciplina = pe.IdDisciplina WHERE pe.IdCurso = ? ORDER BY d.Disciplina');
  $stmtDisc->bind_param('i', $matriculaAceite['IdCurso']);
  $stmtDisc->execute();
  $resDisc = $stmtDisc->get_result();
  while ($row = $resDisc->fetch_assoc()) {
    $disciplinasCurso[] = $row;
  }
  $stmtDisc->close();
}

$stmtPend = $conn->prepare("SELECT c.Curso, m.Status
  FROM matriculas m
  JOIN cursos c ON c.IdCurso = m.IdCurso
  WHERE m.IdAluno = ? AND LOWER(COALESCE(m.Status, '')) = 'pendente'");
$stmtPend->bind_param('i', $idAluno);
$stmtPend->execute();
$resPend = $stmtPend->get_result();
while ($row = $resPend->fetch_assoc()) {
  $pedidosPendentes[] = $row;
}
$stmtPend->close();

$cursosLookup = [];
$resultCursos = $conn->query('SELECT IdCurso, Curso FROM cursos ORDER BY Curso');
if ($resultCursos) {
  while ($row = $resultCursos->fetch_assoc()) {
    $cursosLookup[] = $row;
  }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Area do Aluno - IPCA</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
  <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
  <script src="../js/ficha-transfer.js?v=<?php echo (string)@filemtime(__DIR__ . '/../js/ficha-transfer.js'); ?>" defer></script>
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1>Area do Aluno</h1>
      <p>Preenche a ficha e acompanha o estado para poderes enviar matricula.</p>
    </header>

    <nav>
      <a href="aluno.php">Minha Area</a>
      <a href="ficha_aluno.php">Ficha de Aluno</a>
      <a href="aluno_notas.php">Minhas Notas</a>
      <a href="?action=logout">Terminar sessao</a>
    </nav>

    <?php if ($message !== ''): ?>
      <div class="message <?php echo e($type); ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="form-box">
      <h3>Ficha de Aluno</h3>
      <?php if (!$temFicha): ?>
        <p>Ainda nao tens ficha de aluno submetida.</p>
        <p><a class="primary-link" href="ficha_aluno.php">Preencher ficha de aluno</a></p>
      <?php else: ?>
        <?php
          $payloadFichaAluno = [
            'modo' => 'aluno',
            'nome' => (string)($fichaAluno['nome'] ?? ''),
            'idade' => (int)($fichaAluno['idade'] ?? 0),
            'telefone' => (string)($fichaAluno['telefone'] ?? ''),
            'morada' => (string)($fichaAluno['morada'] ?? ''),
            'nif' => (string)($fichaAluno['nif'] ?? ''),
            'data_nascimento' => (string)($fichaAluno['data_nascimento'] ?? ''),
            'status' => (string)($fichaAluno['Status'] ?? ''),
            'id_user' => (int)($fichaAluno['IdUser'] ?? $idAluno),
            'foto_data_url' => fotoDataUrlFromBlob($fichaAluno['foto'] ?? null),
            'foto_url' => 'aluno.php?action=foto_ficha',
          ];
        ?>
        <script id="fichaAlunoData" type="application/json"><?php echo json_encode($payloadFichaAluno, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
        <p><strong>Nome:</strong> <?php echo e($fichaAluno['nome']); ?></p>
        <p><strong>Status da ficha:</strong> <?php echo e($fichaAluno['Status']); ?></p>
        <p>
          <button type="button" id="btnExportarFichaAluno">Transferir ficha em PDF</button>
        </p>
        <?php if (!empty($fichaAluno['foto'])): ?>
          <img class="photo-preview" src="aluno.php?action=foto_ficha" alt="Foto da ficha">
        <?php endif; ?>
        <?php if ($statusFicha === 'rejeitada'): ?>
          <p>A tua ficha foi rejeitada. Atualiza os dados e volta a submeter.</p>
          <p><a class="primary-link" href="ficha_aluno.php">Editar ficha</a></p>
        <?php elseif ($statusFicha === 'rascunho'): ?>
          <p>A tua ficha esta em Rascunho. Submete para validacao.</p>
          <form method="post">
            <input type="hidden" name="action" value="submit_ficha">
            <button type="submit">Submeter ficha</button>
          </form>
        <?php elseif ($statusFicha === 'submetida' || $statusFicha === 'pendente'): ?>
          <p>A tua ficha esta submetida e em analise. Enquanto isso, nao podes enviar matriculas.</p>
        <?php elseif ($statusFicha === 'aprovada'): ?>
          <p>Ficha aprovada. Ja podes enviar matriculas.</p>
        <?php else: ?>
          <p>Atualiza ou submete a tua ficha para poderes continuar.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="form-box">
      <h3>Matricula</h3>
      <?php if (!$matriculaAceite): ?>
        <p><a class="primary-link" href="aluno_cursos.php">Ver cursos e plano de estudos</a></p>
      <?php endif; ?>

      <?php if (!$podeMatricular): ?>
        <p>Envio de matricula bloqueado até a ficha de aluno ser aprovada.</p>
      <?php elseif ($matriculaAceite): ?>
        <p><strong>Estado da matricula:</strong> <?php echo e($matriculaAceite['Status'] ?? 'Aceite'); ?></p>
        <p><strong>Curso:</strong> <?php echo e($matriculaAceite['Curso']); ?></p>
        <?php if (!empty($disciplinasCurso)): ?>
          <p><strong>Unidades curriculares do curso:</strong></p>
          <ul>
            <?php foreach ($disciplinasCurso as $disc): ?>
              <li><?php echo e($disc['Disciplina']); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php elseif (!empty($pedidosPendentes)): ?>
        <p>O teu pedido de matricula esta em analise:</p>
        <ul>
          <?php foreach ($pedidosPendentes as $p): ?>
            <li><?php echo e($p['Curso']); ?> (<?php echo e($p['Status']); ?>)</li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="request_enrollment">

          <label for="pedido_IdCurso">Curso pretendido</label>
          <select id="pedido_IdCurso" name="IdCurso" required>
            <option value="">Selecione</option>
            <?php foreach ($cursosLookup as $curso): ?>
              <option value="<?php echo e($curso['IdCurso']); ?>"><?php echo e($curso['Curso']); ?></option>
            <?php endforeach; ?>
          </select>

          <label for="pedido_Foto">Comprovativo (PDF, PNG, JPG/JPEG ou WEBP)</label>
          <input id="pedido_Foto" type="file" name="Foto" accept=".pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/png,image/jpeg,image/webp" required>

          <button type="submit">Enviar pedido de matricula</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
<?php $conn->close(); ?>

