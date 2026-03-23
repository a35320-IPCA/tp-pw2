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

$conn = new mysqli('localhost', 'root', '', 'ipcapw');
if ($conn->connect_error) {
  die('Falha na ligação à base de dados: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
$conn->query("ALTER TABLE matriculas ADD COLUMN IF NOT EXISTS Status VARCHAR(20) NOT NULL DEFAULT 'Aceite'");
$conn->query("ALTER TABLE matriculas ADD COLUMN IF NOT EXISTS Data TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
$conn->query("ALTER TABLE matriculas ADD COLUMN IF NOT EXISTS IdFuncionario INT NULL");
$conn->query("ALTER TABLE ficha_aluno MODIFY Status VARCHAR(50) NOT NULL DEFAULT 'Rascunho'");

function e($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizarStatus($status)
{
  $statusRaw = strtolower(trim((string)$status));
  if ($statusRaw === 'aceite') {
    return 'Aceite';
  }
  if ($statusRaw === 'pendente') {
    return 'Pendente';
  }
  if ($statusRaw === 'rejeitada' || $statusRaw === 'recusado') {
    return 'Rejeitada';
  }
  return '';
}

function mensagemErroBaseDados($operacao, mysqli $conn)
{
  if ((int)$conn->errno === 1062) {
    return 'Já existe um registo com os mesmos dados.';
  }
  return 'Não foi possível ' . $operacao . ' neste momento.';
}

function redirectWithMessage($type, $message)
{
  $type = urlencode($type);
  $message = urlencode($message);
  header("Location: matriculas.php?type={$type}&message={$message}");
  exit;
}

function getUploadedImageBlob($fieldName, &$errorMessage)
{
  $errorMessage = '';
  if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
    $errorMessage = 'Ocorreu um erro no carregamento da imagem.';
    return false;
  }
  $tmpFile = $_FILES[$fieldName]['tmp_name'];
  $imageData = file_get_contents($tmpFile);
  if ($imageData === false) {
    $errorMessage = 'Não foi possível ler a imagem enviada.';
    return false;
  }

  $mimeUpload = detectarMimeImagem($imageData);
  $mimesPermitidos = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];
  if (!in_array($mimeUpload, $mimesPermitidos, true)) {
    $errorMessage = 'Formato inválido. Envie PDF, PNG, JPG/JPEG ou WEBP.';
    return false;
  }

  if (strlen($imageData) > 5 * 1024 * 1024) {
    $errorMessage = 'O ficheiro excede o tamanho máximo permitido (5 MB).';
    return false;
  }
  return $imageData;
}

function detectarMimeImagem($blob)
{
  $bytes = (string)$blob;
  if ($bytes === '') {
    return 'application/octet-stream';
  }

  $imageInfo = @getimagesizefromstring($bytes);
  if (is_array($imageInfo) && !empty($imageInfo['mime']) && str_starts_with((string)$imageInfo['mime'], 'image/')) {
    return (string)$imageInfo['mime'];
  }

  if (strncmp($bytes, '%PDF-', 5) === 0) {
    return 'application/pdf';
  }

  if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
    return 'image/jpeg';
  }
  if (strncmp($bytes, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) {
    return 'image/png';
  }
  if (strncmp($bytes, 'GIF87a', 6) === 0 || strncmp($bytes, 'GIF89a', 6) === 0) {
    return 'image/gif';
  }
  if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
    return 'image/webp';
  }

  return 'application/octet-stream';
}

function prepararBlobParaResposta($blob, &$mimeType)
{
  if ($mimeType === 'image/webp' && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
    $img = @imagecreatefromstring($blob);
    if ($img !== false) {
      ob_start();
      imagejpeg($img, null, 90);
      $jpegData = ob_get_clean();
      imagedestroy($img);
      if (is_string($jpegData) && $jpegData !== '') {
        $mimeType = 'image/jpeg';
        return $jpegData;
      }
    }
  }
  return $blob;
}

$perfilAtual = strtolower(trim((string)($_SESSION['perfil'] ?? '')));
$isAluno = $perfilAtual === 'aluno';
$isFuncionario = $perfilAtual === 'funcionario';
$isGestor = !$isAluno && !$isFuncionario;
$tipoUtilizador = $isAluno ? 'Aluno' : ($isFuncionario ? 'Funcionario' : 'Gestor');

if ($isAluno) {
  header('Location: aluno.php');
  exit;
}

$idFuncionarioSessao = (int)($_SESSION['iduser'] ?? 0);
if ($idFuncionarioSessao <= 0) {
  $loginSessao = (string)($_SESSION['login'] ?? '');
  if ($loginSessao !== '') {
    $stmtUserSessao = $conn->prepare('SELECT IdUser FROM users WHERE LOWER(login) = LOWER(?) LIMIT 1');
    $stmtUserSessao->bind_param('s', $loginSessao);
    $stmtUserSessao->execute();
    $rowUserSessao = $stmtUserSessao->get_result()->fetch_assoc();
    $stmtUserSessao->close();
    $idFuncionarioSessao = (int)($rowUserSessao['IdUser'] ?? 0);
    if ($idFuncionarioSessao > 0) {
      $_SESSION['iduser'] = $idFuncionarioSessao;
    }
  }
}

if (isset($_GET['action']) && $_GET['action'] === 'foto') {
  $idAlunoFoto = (int)($_GET['IdAluno'] ?? ($_GET['id_aluno'] ?? 0));
  $stmtFoto = $conn->prepare('SELECT Foto FROM matriculas WHERE IdAluno = ?');
  $stmtFoto->bind_param('i', $idAlunoFoto);
  $stmtFoto->execute();
  $fotoRow = $stmtFoto->get_result()->fetch_assoc();
  $stmtFoto->close();

  if (!$fotoRow || $fotoRow['Foto'] === null) {
    http_response_code(404);
    exit;
  }

  $mimeType = detectarMimeImagem($fotoRow['Foto']);
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $detectedMime = finfo_buffer($finfo, $fotoRow['Foto']);
      finfo_close($finfo);
      if (is_string($detectedMime) && (str_starts_with($detectedMime, 'image/') || $detectedMime === 'application/pdf')) {
        $mimeType = $detectedMime;
      }
    }
  }

  $blobResposta = prepararBlobParaResposta($fotoRow['Foto'], $mimeType);

  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Content-Type: ' . $mimeType);
  $isDownload = (string)($_GET['download'] ?? '') === '1';
  $disposition = $isDownload ? 'attachment' : 'inline';
  $ext = 'bin';
  if ($mimeType === 'application/pdf') {
    $ext = 'pdf';
  }
  if ($mimeType === 'image/jpeg') {
    $ext = 'jpg';
  }
  if ($mimeType === 'image/png') {
    $ext = 'png';
  }
  if ($mimeType === 'image/gif') {
    $ext = 'gif';
  }
  if ($mimeType === 'image/webp') {
    $ext = 'webp';
  }
  header('Content-Disposition: ' . $disposition . '; filename="certificado_' . $idAlunoFoto . '.' . $ext . '"');
  header('Content-Length: ' . strlen($blobResposta));
  echo $blobResposta;
  exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'certificado') {
  $idAlunoCert = (int)($_GET['IdAluno'] ?? ($_GET['id_aluno'] ?? 0));
  if ($idAlunoCert <= 0) {
    http_response_code(400);
    echo 'Certificado inválido.';
    exit;
  }
  ?>
  <!DOCTYPE html>
  <html lang="pt">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visualizar Certificado</title>
    <style>
      body { margin: 0; background: #0b1d44; color: #f3f6ff; font-family: Arial, sans-serif; }
      .wrap { max-width: 980px; margin: 0 auto; padding: 18px; }
      .box { height: calc(100vh - 120px); background: #102a63; border: 1px solid #2d4c8b; border-radius: 10px; overflow: hidden; }
      object { width: 100%; height: 100%; }
      .actions { margin-top: 12px; display: flex; gap: 12px; }
      .actions a { color: #b8d3ff; text-decoration: underline; }
    </style>
  </head>
  <body>
    <div class="wrap">
      <h2>Certificado</h2>
      <div class="box">
        <object data="matriculas.php?action=foto&IdAluno=<?php echo e($idAlunoCert); ?>" type="application/pdf">
          <object data="matriculas.php?action=foto&IdAluno=<?php echo e($idAlunoCert); ?>" type="image/*">
            <p>O navegador não conseguiu pré-visualizar o certificado.</p>
          </object>
        </object>
      </div>
      <div class="actions">
        <a href="matriculas.php?action=foto&IdAluno=<?php echo e($idAlunoCert); ?>&download=1">Descarregar certificado</a>
        <a href="matriculas.php">Voltar</a>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postAction = $_POST['action'] ?? '';

  $acoesPermitidas = ['validate_request', 'create', 'update', 'delete'];
  if (!in_array($postAction, $acoesPermitidas, true)) {
    redirectWithMessage('error', 'Ação inválida.');
  }

  if (($isFuncionario || $isGestor) && $postAction === 'validate_request') {
    $idAluno = (int)($_POST['IdAluno'] ?? 0);
    $novoStatus = normalizarStatus($_POST['Status'] ?? '');

    if ($idAluno <= 0) {
      redirectWithMessage('error', 'Aluno inválido para validação do pedido.');
    }

    if (!in_array($novoStatus, ['Aceite', 'Rejeitada'], true)) {
      redirectWithMessage('error', 'Estado inválido para validação de pedido.');
    }

    if ($idFuncionarioSessao <= 0) {
      redirectWithMessage('error', 'Não foi possível identificar o utilizador que validou o pedido.');
    }

    $stmt = $conn->prepare("UPDATE matriculas SET Status = ?, IdFuncionario = ? WHERE IdAluno = ? AND LOWER(COALESCE(Status, '')) = 'pendente'");
    $stmt->bind_param('sii', $novoStatus, $idFuncionarioSessao, $idAluno);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('validar o pedido', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Pedido validado com sucesso.' : $erroDb);
  }

  if (!$isGestor) {
    redirectWithMessage('error', 'Sem permissões para alterar matrículas.');
  }

  if ($postAction === 'create') {
    $idAlunoSelecionado = (int)($_POST['IdAlunoSelecionado'] ?? 0);
    $idCurso = (int)($_POST['IdCurso'] ?? 0);
    $status = normalizarStatus($_POST['Status'] ?? 'Aceite');

    if ($idAlunoSelecionado <= 0) {
      redirectWithMessage('error', 'Selecione um aluno válido.');
    }
    if ($idCurso <= 0) {
      redirectWithMessage('error', 'Selecione um curso válido.');
    }
    if ($status === '') {
      redirectWithMessage('error', 'Estado de matrícula inválido.');
    }

    $stmtAluno = $conn->prepare('SELECT login FROM users WHERE IdUser = ? LIMIT 1');
    if (!$stmtAluno) {
      redirectWithMessage('error', mensagemErroBaseDados('obter o aluno', $conn));
    }
    $stmtAluno->bind_param('i', $idAlunoSelecionado);
    $stmtAluno->execute();
    $alunoSelecionado = $stmtAluno->get_result()->fetch_assoc();
    $stmtAluno->close();
    if (!$alunoSelecionado) {
      redirectWithMessage('error', 'Aluno selecionado não existe.');
    }

    $stmtCurso = $conn->prepare('SELECT IdCurso FROM cursos WHERE IdCurso = ? LIMIT 1');
    if (!$stmtCurso) {
      redirectWithMessage('error', mensagemErroBaseDados('obter o curso', $conn));
    }
    $stmtCurso->bind_param('i', $idCurso);
    $stmtCurso->execute();
    $cursoSelecionado = $stmtCurso->get_result()->fetch_assoc();
    $stmtCurso->close();
    if (!$cursoSelecionado) {
      redirectWithMessage('error', 'Curso selecionado não existe.');
    }

    $stmtExisteMatricula = $conn->prepare('SELECT IdAluno FROM matriculas WHERE IdAluno = ? LIMIT 1');
    if (!$stmtExisteMatricula) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a matrícula existente', $conn));
    }
    $stmtExisteMatricula->bind_param('i', $idAlunoSelecionado);
    $stmtExisteMatricula->execute();
    $matriculaExistente = $stmtExisteMatricula->get_result()->fetch_assoc();
    $stmtExisteMatricula->close();
    if ($matriculaExistente) {
      redirectWithMessage('error', 'Já existe uma matrícula para o aluno selecionado.');
    }

    $uploadError = '';
    $fotoBlob = getUploadedImageBlob('Foto', $uploadError);
    if ($fotoBlob === false) {
      redirectWithMessage('error', $uploadError);
    }

    $nome = (string)$alunoSelecionado['login'];
    $stmt = $conn->prepare('INSERT INTO matriculas (IdAluno, Nome, IdCurso, Foto, Status, Data) VALUES (?, ?, ?, ?, ?, NOW())');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('criar a matrícula', $conn));
    }
    $stmt->bind_param('isibs', $idAlunoSelecionado, $nome, $idCurso, $fotoBlob, $status);
    $stmt->send_long_data(3, $fotoBlob);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('criar a matrícula', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Matrícula criada com sucesso.' : $erroDb);
  }

  if ($postAction === 'update') {
    $idAlunoAnterior = (int)($_POST['IdAluno'] ?? 0);
    $idAlunoSelecionado = (int)($_POST['IdAlunoSelecionado'] ?? 0);
    $idCurso = (int)($_POST['IdCurso'] ?? 0);
    $status = normalizarStatus($_POST['Status'] ?? 'Aceite');

    if ($idAlunoAnterior <= 0) {
      redirectWithMessage('error', 'Matrícula inválida para atualização.');
    }
    if ($idAlunoSelecionado <= 0) {
      redirectWithMessage('error', 'Selecione um aluno válido.');
    }
    if ($idCurso <= 0) {
      redirectWithMessage('error', 'Selecione um curso válido.');
    }
    if ($status === '') {
      redirectWithMessage('error', 'Estado de matrícula inválido.');
    }

    $stmtMatriculaAtual = $conn->prepare('SELECT IdAluno FROM matriculas WHERE IdAluno = ? LIMIT 1');
    if (!$stmtMatriculaAtual) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a matrícula', $conn));
    }
    $stmtMatriculaAtual->bind_param('i', $idAlunoAnterior);
    $stmtMatriculaAtual->execute();
    $matriculaAtual = $stmtMatriculaAtual->get_result()->fetch_assoc();
    $stmtMatriculaAtual->close();
    if (!$matriculaAtual) {
      redirectWithMessage('error', 'A matrícula que tentou atualizar já não existe.');
    }

    $stmtAluno = $conn->prepare('SELECT login FROM users WHERE IdUser = ? LIMIT 1');
    if (!$stmtAluno) {
      redirectWithMessage('error', mensagemErroBaseDados('obter o aluno', $conn));
    }
    $stmtAluno->bind_param('i', $idAlunoSelecionado);
    $stmtAluno->execute();
    $alunoSelecionado = $stmtAluno->get_result()->fetch_assoc();
    $stmtAluno->close();
    if (!$alunoSelecionado) {
      redirectWithMessage('error', 'Aluno selecionado não existe.');
    }

    $stmtCurso = $conn->prepare('SELECT IdCurso FROM cursos WHERE IdCurso = ? LIMIT 1');
    if (!$stmtCurso) {
      redirectWithMessage('error', mensagemErroBaseDados('obter o curso', $conn));
    }
    $stmtCurso->bind_param('i', $idCurso);
    $stmtCurso->execute();
    $cursoSelecionado = $stmtCurso->get_result()->fetch_assoc();
    $stmtCurso->close();
    if (!$cursoSelecionado) {
      redirectWithMessage('error', 'Curso selecionado não existe.');
    }

    $uploadError = '';
    $fotoBlob = getUploadedImageBlob('Foto', $uploadError);
    if ($fotoBlob === false) {
      redirectWithMessage('error', $uploadError);
    }

    $nome = (string)$alunoSelecionado['login'];
    $setIdFuncionario = in_array($status, ['Aceite', 'Rejeitada'], true) && $idFuncionarioSessao > 0;

    if ($fotoBlob !== null) {
      if ($setIdFuncionario) {
        $stmt = $conn->prepare('UPDATE matriculas SET IdAluno = ?, Nome = ?, IdCurso = ?, Foto = ?, Status = ?, IdFuncionario = ? WHERE IdAluno = ?');
        $stmt->bind_param('isibsii', $idAlunoSelecionado, $nome, $idCurso, $fotoBlob, $status, $idFuncionarioSessao, $idAlunoAnterior);
        $stmt->send_long_data(3, $fotoBlob);
      } else {
        $stmt = $conn->prepare('UPDATE matriculas SET IdAluno = ?, Nome = ?, IdCurso = ?, Foto = ?, Status = ? WHERE IdAluno = ?');
        $stmt->bind_param('isibsi', $idAlunoSelecionado, $nome, $idCurso, $fotoBlob, $status, $idAlunoAnterior);
        $stmt->send_long_data(3, $fotoBlob);
      }
    } else {
      if ($setIdFuncionario) {
        $stmt = $conn->prepare('UPDATE matriculas SET IdAluno = ?, Nome = ?, IdCurso = ?, Status = ?, IdFuncionario = ? WHERE IdAluno = ?');
        $stmt->bind_param('isisii', $idAlunoSelecionado, $nome, $idCurso, $status, $idFuncionarioSessao, $idAlunoAnterior);
      } else {
        $stmt = $conn->prepare('UPDATE matriculas SET IdAluno = ?, Nome = ?, IdCurso = ?, Status = ? WHERE IdAluno = ?');
        $stmt->bind_param('isisi', $idAlunoSelecionado, $nome, $idCurso, $status, $idAlunoAnterior);
      }
    }

    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('atualizar a matrícula', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Matrícula atualizada com sucesso.' : $erroDb);
  }

  if ($postAction === 'delete') {
    $idAluno = (int)($_POST['IdAluno'] ?? 0);
    if ($idAluno <= 0) {
      redirectWithMessage('error', 'Aluno inválido para remoção da matrícula.');
    }

    $stmtExiste = $conn->prepare('SELECT IdAluno FROM matriculas WHERE IdAluno = ? LIMIT 1');
    if (!$stmtExiste) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a matrícula', $conn));
    }
    $stmtExiste->bind_param('i', $idAluno);
    $stmtExiste->execute();
    $matriculaExiste = $stmtExiste->get_result()->fetch_assoc();
    $stmtExiste->close();
    if (!$matriculaExiste) {
      redirectWithMessage('error', 'A matrícula que tentou remover já não existe.');
    }

    $stmt = $conn->prepare('DELETE FROM matriculas WHERE IdAluno = ?');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('remover a matrícula', $conn));
    }
    $stmt->bind_param('i', $idAluno);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('remover a matrícula', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Matrícula removida com sucesso.' : $erroDb);
  }
}

$action = $_GET['action'] ?? 'list';
$message = $_GET['message'] ?? '';
$type = $_GET['type'] ?? '';
$editData = null;

if ($action === 'edit') {
  $idAluno = (int)($_GET['IdAluno'] ?? 0);
  if ($idAluno <= 0) {
    redirectWithMessage('error', 'Matrícula inválida para edição.');
  }
  $stmt = $conn->prepare('SELECT * FROM matriculas WHERE IdAluno = ?');
  if (!$stmt) {
    redirectWithMessage('error', mensagemErroBaseDados('obter a matrícula', $conn));
  }
  $stmt->bind_param('i', $idAluno);
  $stmt->execute();
  $editData = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$editData) {
    redirectWithMessage('error', 'A matrícula selecionada não foi encontrada.');
  }
}

$cursosLookup = [];
$resultCursos = $conn->query('SELECT IdCurso, Curso FROM cursos ORDER BY Curso');
if ($resultCursos) {
  while ($row = $resultCursos->fetch_assoc()) {
    $cursosLookup[] = $row;
  }
}

$alunosLookup = [];
$resultAlunos = $conn->query('SELECT users.IdUser AS IdAluno, ficha_aluno.nome AS Nome FROM users INNER JOIN ficha_aluno ON users.IdUser = ficha_aluno.IdUser ORDER BY nome');
if ($resultAlunos) {
  while ($row = $resultAlunos->fetch_assoc()) {
    $alunosLookup[] = $row;
  }
}

$filtroDefault = $isFuncionario ? 'pendente' : 'todas';
$filtroMatriculas = strtolower(trim((string)($_GET['filtro_matriculas'] ?? $filtroDefault)));
$filtrosPermitidos = ['todas', 'aceite', 'pendente', 'rejeitada'];
if (!in_array($filtroMatriculas, $filtrosPermitidos, true)) {
  $filtroMatriculas = $filtroDefault;
}
$whereFiltroStatus = '';
if ($filtroMatriculas === 'pendente') {
  $whereFiltroStatus = " AND LOWER(COALESCE(m.Status, '')) = 'pendente'";
}
if ($filtroMatriculas === 'aceite') {
  $whereFiltroStatus = " AND LOWER(COALESCE(m.Status, 'aceite')) = 'aceite'";
}
if ($filtroMatriculas === 'rejeitada') {
  $whereFiltroStatus = " AND LOWER(COALESCE(m.Status, '')) IN ('rejeitada', 'recusado')";
}

$filtroLabels = [
  'todas' => 'Geral',
  'aceite' => 'Aceite',
  'pendente' => 'Pendente',
  'rejeitada' => 'Rejeitada',
];

$filtroCursoId = (int)($_GET['id_curso'] ?? 0);
$whereFiltroCurso = '';
if ($filtroCursoId > 0) {
  $whereFiltroCurso = ' AND m.IdCurso = ' . $filtroCursoId;
}

$rows = $conn->query("SELECT m.IdAluno, m.Nome, c.Curso, m.Status, m.Foto FROM matriculas m JOIN cursos c ON c.IdCurso = m.IdCurso WHERE 1=1 {$whereFiltroStatus}{$whereFiltroCurso} ORDER BY m.IdAluno DESC");

?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Matrículas - IPCA VNF</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
  <style>
.form-box .table-wrap {
			width: 100%;
			margin: 0;
			padding: 0;
			overflow-x: auto;
		}

		.matriculas-table {
			width: 100% !important;
			min-width: 100% !important;
			display: table !important;
			table-layout: fixed !important;
			margin: 12px 0 0 0 !important;
			border-radius: 0 !important;
			box-shadow: none !important;
		}

		.matriculas-table th,
		.matriculas-table td {
			font: inherit !important;
			font-weight: 400 !important;
			text-align: left !important;
			vertical-align: middle !important;
			word-break: break-word;
		}

    .matriculas-table th:nth-child(1),
    .matriculas-table td:nth-child(1) {
      width: 24% !important;
    }

    .matriculas-table th:nth-child(2),
    .matriculas-table td:nth-child(2) {
      width: 26% !important;
    }

    .matriculas-table th:nth-child(3),
    .matriculas-table td:nth-child(3) {
      width: 16% !important;
    }

    .matriculas-table th:nth-child(4),
    .matriculas-table td:nth-child(4) {
      width: 14% !important;
    }

    .matriculas-table th:nth-child(5),
    .matriculas-table td:nth-child(5) {
      width: 20% !important;
    }

		.matriculas-table th {
			font-weight: 800 !important;
		}

		.matriculas-table th:last-child,
		.matriculas-table td:last-child {
			white-space: normal !important;
		}

		.matriculas-table td.actions {
      display: table-cell !important;
      padding-top: 10px !important;
      padding-bottom: 10px !important;
      white-space: nowrap !important;
			overflow: visible;
		}

    .matriculas-table td.actions > a,
    .matriculas-table td.actions > form {
      display: inline-flex;
      vertical-align: middle;
      margin: 0;
      margin-right: 8px;
		}

    .matriculas-table td.actions > a:last-child,
    .matriculas-table td.actions > form:last-child {
      margin-right: 0;
    }

		.matriculas-table .actions button {
			margin-top: 0 !important;
			line-height: 1.2;
			padding: 10px 14px;
		}
  </style>
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1><?php echo $isFuncionario ? 'Pedidos de Matrícula' : 'Matrículas'; ?></h1>
      <p>Gestão de matrículas e validações.</p>
      <span class="role-badge">Tipo de utilizador: <?php echo e($tipoUtilizador); ?></span>
    </header>

    <nav>
      <?php if ($isGestor): ?>
        <a href="disciplinas.php">Unidades Curriculares</a>
        <a href="cursos.php">Cursos</a>
        <a href="matriculas.php">Matrículas</a>
        <a href="fichas.php">Fichas de Aluno</a>
        <a href="planos.php">Planos de Estudo</a>
        <a href="notas.php">Notas e Pautas</a>
      <?php else: ?>
        <a href="matriculas.php">Matrículas</a>
        <a href="notas.php">Notas e Pautas</a>
      <?php endif; ?>
      <a href="?action=logout">Terminar sessão</a>
    </nav>

    <?php if ($message !== ''): ?>
      <div class="message <?php echo e($type); ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if (($action === 'create' || $action === 'edit') && $isGestor): ?>
      <div class="form-box">
        <h3><?php echo $action === 'edit' ? 'Editar Matrícula' : 'Nova Matrícula'; ?></h3>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="<?php echo $action === 'edit' ? 'update' : 'create'; ?>">
          <?php if ($action === 'edit' && $editData): ?>
            <input type="hidden" name="IdAluno" value="<?php echo e($editData['IdAluno']); ?>">
          <?php endif; ?>

          <label>Aluno</label>
          <select name="IdAlunoSelecionado" required>
            <option value="">Selecione</option>
            <?php foreach ($alunosLookup as $alunoItem): ?>
              <option value="<?php echo e($alunoItem['IdAluno']); ?>" <?php echo (int)($editData['IdAluno'] ?? 0) === (int)$alunoItem['IdAluno'] ? 'selected' : ''; ?>>
                <?php echo e($alunoItem['IdAluno'] . ' - ' . $alunoItem['Nome']); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label>Curso</label>
          <select name="IdCurso" required>
            <option value="">Selecione</option>
            <?php foreach ($cursosLookup as $cursoItem): ?>
              <option value="<?php echo e($cursoItem['IdCurso']); ?>" <?php echo (int)($editData['IdCurso'] ?? 0) === (int)$cursoItem['IdCurso'] ? 'selected' : ''; ?>>
                <?php echo e($cursoItem['Curso']); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label>Status</label>
          <?php $statusAtual = (string)($editData['Status'] ?? 'Aceite'); ?>
          <select name="Status" required>
            <option value="Aceite" <?php echo $statusAtual === 'Aceite' ? 'selected' : ''; ?>>Aceite</option>
            <option value="Pendente" <?php echo $statusAtual === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
            <option value="Rejeitada" <?php echo $statusAtual === 'Rejeitada' ? 'selected' : ''; ?>>Rejeitada</option>
          </select>

          <label>Certificado</label>
          <input type="file" name="Foto" accept=".pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/png,image/jpeg,image/webp">

          <button type="submit"><?php echo $action === 'edit' ? 'Atualizar' : 'Criar'; ?></button>
          <a class="back-link" href="matriculas.php">Voltar à lista</a>
        </form>
      </div>
    <?php else: ?>
      <?php if ($isGestor): ?>
        <p><a class="primary-link" href="matriculas.php?action=create">+ Nova matrícula</a></p>
      <?php endif; ?>

      <div class="filter-panel">
        <p class="filter-title">Filtrar matrículas</p>

        <form method="get" class="filter-course-form">
          <label for="id_curso">Curso</label>
          <select id="id_curso" name="id_curso">
            <option value="0">Todos os cursos</option>
            <?php foreach ($cursosLookup as $cursoItem): ?>
              <option value="<?php echo e($cursoItem['IdCurso']); ?>" <?php echo $filtroCursoId === (int)$cursoItem['IdCurso'] ? 'selected' : ''; ?>>
                <?php echo e($cursoItem['Curso']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="filtro_matriculas" value="<?php echo e($filtroMatriculas); ?>">
          <button type="submit">Aplicar filtro</button>
        </form>
<br>
        <div class="filter-bar" role="group" aria-label="Filtros de matrículas">
          <?php foreach ($filtroLabels as $filtroKey => $filtroLabel): ?>
            <a
              class="filter-pill <?php echo $filtroMatriculas === $filtroKey ? 'active' : ''; ?>"
              href="matriculas.php?filtro_matriculas=<?php echo e($filtroKey); ?>&id_curso=<?php echo e($filtroCursoId); ?>"
            >
              <?php echo e($filtroLabel); ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-box">
        <div class="table-wrap">
          <table class="matriculas-table">
            <tr>
              <th>Nome</th>
              <th>Curso</th>
              <th>Status</th>
              <th>Certificado</th>
              <th>Ações</th>
            </tr>
            <?php while ($row = $rows->fetch_assoc()): ?>
              <?php $statusRow = strtolower(trim((string)($row['Status'] ?? ''))); ?>
              <?php $mimeCertificado = detectarMimeImagem($row['Foto'] ?? null); ?>
              <?php $isImagemCertificado = str_starts_with($mimeCertificado, 'image/'); ?>
              <tr>
                <td><?php echo e($row['Nome']); ?></td>
                <td><?php echo e($row['Curso']); ?></td>
                <td><?php echo e($row['Status'] ?? 'Aceite'); ?></td>
                <td>
                  <?php if (!empty($row['Foto']) && $isImagemCertificado): ?>
                    <img class="photo-mini" src="data:<?php echo e($mimeCertificado); ?>;base64,<?php echo base64_encode($row['Foto']); ?>" alt="Certificado">
                  <?php elseif (!empty($row['Foto']) && $mimeCertificado === 'application/pdf'): ?>
                    <span>PDF</span>
                  <?php else: ?>
                    <span>Sem ficheiro</span>
                  <?php endif; ?>
                </td>
                <td class="actions">
                  <?php if ($isGestor): ?>
                    <a href="matriculas.php?action=edit&IdAluno=<?php echo e($row['IdAluno']); ?>">Editar</a>
                    <form class="inline" method="post" onsubmit="return confirm('Remover matrícula?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="IdAluno" value="<?php echo e($row['IdAluno']); ?>">
                      <button type="submit">Eliminar</button>
                    </form>
                  <?php elseif ($isFuncionario && $statusRow === 'pendente'): ?>
                    <form class="inline" method="post">
                      <input type="hidden" name="action" value="validate_request">
                      <input type="hidden" name="IdAluno" value="<?php echo e($row['IdAluno']); ?>">
                      <input type="hidden" name="Status" value="Aceite">
                      <button type="submit">Aceitar</button>
                    </form>
                    <form class="inline" method="post">
                      <input type="hidden" name="action" value="validate_request">
                      <input type="hidden" name="IdAluno" value="<?php echo e($row['IdAluno']); ?>">
                      <input type="hidden" name="Status" value="Rejeitada">
                      <button type="submit">Recusar</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          </table>
        </div>
      </div>

    <?php endif; ?>
  </div>
</body>
</html>
<?php $conn->close(); ?>

