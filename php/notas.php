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

function e($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatarDataHora($valor)
{
  $valor = (string)$valor;
  if ($valor === '' || $valor === '0000-00-00 00:00:00') {
    return '-';
  }
  $timestamp = strtotime($valor);
  if ($timestamp === false) {
    return '-';
  }
  return date('d/m/Y H:i:s', $timestamp);
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
  header("Location: notas.php?type={$type}&message={$message}");
  exit;
}

function normalizarEpoca($valor)
{
  $permitidas = ['Normal', 'Recurso', 'Especial'];
  foreach ($permitidas as $epoca) {
    if (mb_strtolower(trim((string)$valor), 'UTF-8') === mb_strtolower($epoca, 'UTF-8')) {
      return $epoca;
    }
  }
  return '';
}

function anoLetivoValido($valor)
{
  $valor = trim((string)$valor);
  if (preg_match('/^\d{4}\/\d{4}$/', $valor) !== 1) {
    return false;
  }
  $partesAno = explode('/', $valor);
  $anoInicio = (int)($partesAno[0] ?? 0);
  $anoFim = (int)($partesAno[1] ?? 0);
  return $anoFim === ($anoInicio + 1);
}

function gerarOpcoesAnoLetivo()
{
  $anoAtual = (int)date('Y');
  $mesAtual = (int)date('n');
  $inicioAnoBase = $mesAtual >= 8 ? $anoAtual : ($anoAtual - 1);

  $opcoes = [];
  for ($offset = -2; $offset <= 3; $offset++) {
    $anoInicio = $inicioAnoBase + $offset;
    $opcoes[] = $anoInicio . '/' . ($anoInicio + 1);
  }

  return $opcoes;
}

function parseNota($valor, &$nota)
{
  $raw = trim((string)$valor);
  if ($raw === '') {
    return false;
  }

  if (preg_match('/^\d{1,2}(?:[\.,]\d{1,2})?$/', $raw) !== 1) {
    return false;
  }

  $normalizado = str_replace(',', '.', $raw);
  if (!is_numeric($normalizado)) {
    return false;
  }

  $notaTmp = (float)$normalizado;
  if ($notaTmp < 0 || $notaTmp > 20) {
    return false;
  }

  $nota = $notaTmp;
  return true;
}

function listaInteirosPost($campo)
{
  $lista = $_POST[$campo] ?? [];
  if (!is_array($lista)) {
    return [];
  }
  $resultado = [];
  foreach ($lista as $item) {
    $valor = (int)$item;
    if ($valor > 0 && !in_array($valor, $resultado, true)) {
      $resultado[] = $valor;
    }
  }
  return $resultado;
}

function fetchLookup(mysqli $conn, $table, $idField, $labelField)
{
  $sql = "SELECT {$idField}, {$labelField} FROM {$table} ORDER BY {$labelField}";
  $result = $conn->query($sql);
  $items = [];
  if ($result) {
    while ($row = $result->fetch_assoc()) {
      $items[] = $row;
    }
  }
  return $items;
}

$perfilAtual = strtolower(trim((string)($_SESSION['perfil'] ?? '')));
$isAluno = $perfilAtual === 'aluno';
$isFuncionario = $perfilAtual === 'funcionario';
$isGestor = !$isAluno && !$isFuncionario;
$isStaffAcademico = $isGestor || $isFuncionario;
$tipoUtilizador = $isAluno ? 'Aluno' : ($isFuncionario ? 'Funcionario' : 'Gestor');

if ($isAluno) {
  header('Location: aluno.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$isStaffAcademico) {
    redirectWithMessage('error', 'Sem permissões para gerir notas.');
  }

  $postAction = $_POST['action'] ?? '';
  $acoesPermitidas = ['create', 'update', 'delete', 'prepare_pauta', 'save_pauta'];
  if (!in_array($postAction, $acoesPermitidas, true)) {
    redirectWithMessage('error', 'Ação inválida.');
  }

  if ($postAction === 'prepare_pauta') {
    $idCursoPauta = (int)($_POST['IdCursoPauta'] ?? 0);
    $idDisciplinaPauta = (int)($_POST['IdDisciplinaPauta'] ?? 0);
    $anoLetivoPauta = trim((string)($_POST['AnoLetivoPauta'] ?? ''));
    $epocaPauta = normalizarEpoca($_POST['EpocaPauta'] ?? '');
    $modoPauta = $_POST['ModoPauta'] ?? 'auto';
    $alunosSelecionados = listaInteirosPost('IdAlunosSel');

    if ($idCursoPauta <= 0 || $idDisciplinaPauta <= 0 || !anoLetivoValido($anoLetivoPauta) || $epocaPauta === '') {
      redirectWithMessage('error', 'Dados inválidos para criar pauta (curso, UC, ano letivo e época são obrigatórios).');
    }

    if (!in_array($modoPauta, ['auto', 'manual'], true)) {
      $modoPauta = 'auto';
    }

    $stmtDisciplinaCurso = $conn->prepare('SELECT 1 FROM plano_estudos WHERE IdCurso = ? AND IdDisciplina = ? LIMIT 1');
    if (!$stmtDisciplinaCurso) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a unidade curricular no curso', $conn));
    }
    $stmtDisciplinaCurso->bind_param('ii', $idCursoPauta, $idDisciplinaPauta);
    $stmtDisciplinaCurso->execute();
    $disciplinaNoCurso = $stmtDisciplinaCurso->get_result()->fetch_assoc();
    $stmtDisciplinaCurso->close();
    if (!$disciplinaNoCurso) {
      redirectWithMessage('error', 'A unidade curricular selecionada não pertence ao curso escolhido.');
    }

    $stmtElegiveis = $conn->prepare(
      "SELECT m.IdAluno, COALESCE(fa.nome, u.login, m.Nome) AS NomeAluno
       FROM matriculas m
       LEFT JOIN users u ON u.IdUser = m.IdAluno
       LEFT JOIN ficha_aluno fa ON fa.IdUser = m.IdAluno
       WHERE m.IdCurso = ?
         AND LOWER(COALESCE(m.Status, '')) = 'aceite'
       ORDER BY NomeAluno"
    );
    if (!$stmtElegiveis) {
      redirectWithMessage('error', mensagemErroBaseDados('obter alunos elegíveis', $conn));
    }
    $stmtElegiveis->bind_param('i', $idCursoPauta);
    $stmtElegiveis->execute();
    $resultElegiveis = $stmtElegiveis->get_result();

    $elegiveis = [];
    while ($row = $resultElegiveis->fetch_assoc()) {
      $elegiveis[(int)$row['IdAluno']] = [
        'IdAluno' => (int)$row['IdAluno'],
        'NomeAluno' => (string)$row['NomeAluno'],
      ];
    }
    $stmtElegiveis->close();

    if (empty($elegiveis)) {
      redirectWithMessage('error', 'Não existem alunos elegíveis para a pauta selecionada.');
    }

    $idsPreparar = [];
    if ($modoPauta === 'auto') {
      $idsPreparar = array_keys($elegiveis);
    } else {
      foreach ($alunosSelecionados as $idAlunoSel) {
        if (isset($elegiveis[$idAlunoSel])) {
          $idsPreparar[] = $idAlunoSel;
        }
      }
      if (empty($idsPreparar)) {
        redirectWithMessage('error', 'No modo manual, selecione pelo menos um aluno elegível.');
      }
    }

  }

  if ($postAction === 'save_pauta') {
    $idCursoPauta = (int)($_POST['IdCursoPauta'] ?? 0);
    $idDisciplinaPauta = (int)($_POST['IdDisciplinaPauta'] ?? 0);
    $anoLetivoPauta = trim((string)($_POST['AnoLetivoPauta'] ?? ''));
    $epocaPauta = normalizarEpoca($_POST['EpocaPauta'] ?? '');
    $idsAlunoRaw = $_POST['IdAlunoPauta'] ?? [];
    $notasRaw = $_POST['NotaFinal'] ?? [];

    if (!is_array($idsAlunoRaw) || !is_array($notasRaw)) {
      redirectWithMessage('error', 'Formato inválido para os dados da pauta.');
    }

    if (count($idsAlunoRaw) !== count($notasRaw)) {
      redirectWithMessage('error', 'Os dados da pauta estão inconsistentes. Recarregue e tente novamente.');
    }

    $idsAluno = [];
    foreach ($idsAlunoRaw as $idAlunoRaw) {
      $idAluno = (int)$idAlunoRaw;
      if ($idAluno <= 0) {
        redirectWithMessage('error', 'Foi encontrado um aluno inválido na pauta.');
      }
      $idsAluno[] = $idAluno;
    }

    if (count($idsAluno) !== count(array_unique($idsAluno))) {
      redirectWithMessage('error', 'Existem alunos duplicados na pauta.');
    }

    if (empty($notasRaw)) {
      $notasRaw = [];
    }

    if ($idCursoPauta <= 0 || $idDisciplinaPauta <= 0 || !anoLetivoValido($anoLetivoPauta) || $epocaPauta === '' || empty($idsAluno)) {
      redirectWithMessage('error', 'Dados inválidos para guardar a pauta.');
    }

    $stmtDisciplinaCurso = $conn->prepare('SELECT 1 FROM plano_estudos WHERE IdCurso = ? AND IdDisciplina = ? LIMIT 1');
    if (!$stmtDisciplinaCurso) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a unidade curricular no curso', $conn));
    }
    $stmtDisciplinaCurso->bind_param('ii', $idCursoPauta, $idDisciplinaPauta);
    $stmtDisciplinaCurso->execute();
    $disciplinaNoCurso = $stmtDisciplinaCurso->get_result()->fetch_assoc();
    $stmtDisciplinaCurso->close();
    if (!$disciplinaNoCurso) {
      redirectWithMessage('error', 'A unidade curricular selecionada não pertence ao curso escolhido.');
    }

    $stmtAlunoElegivel = $conn->prepare("SELECT 1 FROM matriculas WHERE IdCurso = ? AND IdAluno = ? AND LOWER(COALESCE(Status, '')) = 'aceite' LIMIT 1");
    $stmtExiste = $conn->prepare('SELECT IdNota FROM notas WHERE IdAluno = ? AND IdDisciplina = ? AND AnoLetivo = ? AND Epoca = ? ORDER BY IdNota DESC LIMIT 1');
    $stmtInsert = $conn->prepare('INSERT INTO notas (IdAluno, IdDisciplina, Nota, AnoLetivo, Epoca, DataLancamento) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmtUpdate = $conn->prepare('UPDATE notas SET Nota = ?, DataLancamento = NOW() WHERE IdNota = ?');

    if (!$stmtAlunoElegivel || !$stmtExiste || !$stmtInsert || !$stmtUpdate) {
      if ($stmtAlunoElegivel) {
        $stmtAlunoElegivel->close();
      }
      if ($stmtExiste) {
        $stmtExiste->close();
      }
      if ($stmtInsert) {
        $stmtInsert->close();
      }
      if ($stmtUpdate) {
        $stmtUpdate->close();
      }
      redirectWithMessage('error', mensagemErroBaseDados('guardar a pauta', $conn));
    }

    $inseridas = 0;
    $atualizadas = 0;

    foreach ($idsAluno as $indice => $idAluno) {
      $valorRaw = trim((string)($notasRaw[$indice] ?? ''));
      if ($valorRaw === '') {
        continue;
      }

      $nota = 0.0;
      if (!parseNota($valorRaw, $nota)) {
        $stmtAlunoElegivel->close();
        $stmtExiste->close();
        $stmtInsert->close();
        $stmtUpdate->close();
        redirectWithMessage('error', 'Existem notas inválidas na pauta.');
      }

      $stmtAlunoElegivel->bind_param('ii', $idCursoPauta, $idAluno);
      $stmtAlunoElegivel->execute();
      $alunoElegivel = $stmtAlunoElegivel->get_result()->fetch_assoc();
      if (!$alunoElegivel) {
        $stmtAlunoElegivel->close();
        $stmtExiste->close();
        $stmtInsert->close();
        $stmtUpdate->close();
        redirectWithMessage('error', 'Foi encontrado um aluno não elegível na pauta.');
      }

      $stmtExiste->bind_param('iiss', $idAluno, $idDisciplinaPauta, $anoLetivoPauta, $epocaPauta);
      $stmtExiste->execute();
      $regExistente = $stmtExiste->get_result()->fetch_assoc();

      if ($regExistente) {
        $idNotaExiste = (int)$regExistente['IdNota'];
        $stmtUpdate->bind_param('di', $nota, $idNotaExiste);
        if (!$stmtUpdate->execute()) {
          $stmtAlunoElegivel->close();
          $stmtExiste->close();
          $stmtInsert->close();
          $stmtUpdate->close();
          redirectWithMessage('error', mensagemErroBaseDados('atualizar registos da pauta', $conn));
        }
        $atualizadas++;
      } else {
        $stmtInsert->bind_param('iidss', $idAluno, $idDisciplinaPauta, $nota, $anoLetivoPauta, $epocaPauta);
        if (!$stmtInsert->execute()) {
          $stmtAlunoElegivel->close();
          $stmtExiste->close();
          $stmtInsert->close();
          $stmtUpdate->close();
          redirectWithMessage('error', mensagemErroBaseDados('registar registos da pauta', $conn));
        }
        $inseridas++;
      }
    }

    $stmtAlunoElegivel->close();
    $stmtExiste->close();
    $stmtInsert->close();
    $stmtUpdate->close();

    $totalGuardado = $inseridas + $atualizadas;
    if ($totalGuardado === 0) {
      redirectWithMessage('error', 'Não foi guardada nenhuma nota na pauta (preencha pelo menos uma nota final).');
    }

    redirectWithMessage('success', 'Pauta guardada com sucesso. Inseridas: ' . $inseridas . '. Atualizadas: ' . $atualizadas . '.');
  }

  if ($postAction === 'create') {
    $idCurso = (int)($_POST['IdCurso'] ?? 0);
    $idAluno = (int)($_POST['IdAluno'] ?? 0);
    $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
    $notaRaw = $_POST['Nota'] ?? '';
    $nota = -1;
    $anoLetivo = trim((string)($_POST['AnoLetivo'] ?? ''));
    $epoca = normalizarEpoca($_POST['Epoca'] ?? '');

    $anoLetivoValido = anoLetivoValido($anoLetivo);

    if (!parseNota($notaRaw, $nota) || $idCurso <= 0 || $idAluno <= 0 || $idDisciplina <= 0 || !$anoLetivoValido || $epoca === '') {
      redirectWithMessage('error', 'Dados inválidos para registo da nota (nota: 0 a 20, ano letivo: YYYY/YYYY, época obrigatória).');
    }

    $stmtAlunoCurso = $conn->prepare("SELECT 1 FROM matriculas WHERE IdCurso = ? AND IdAluno = ? AND LOWER(COALESCE(Status, '')) = 'aceite' LIMIT 1");
    if (!$stmtAlunoCurso) {
      redirectWithMessage('error', mensagemErroBaseDados('validar o aluno no curso', $conn));
    }
    $stmtAlunoCurso->bind_param('ii', $idCurso, $idAluno);
    $stmtAlunoCurso->execute();
    $alunoNoCurso = $stmtAlunoCurso->get_result()->fetch_assoc();
    $stmtAlunoCurso->close();
    if (!$alunoNoCurso) {
      redirectWithMessage('error', 'O aluno selecionado não pertence ao curso escolhido.');
    }

    $stmtDisciplinaCurso = $conn->prepare('SELECT 1 FROM plano_estudos WHERE IdCurso = ? AND IdDisciplina = ? LIMIT 1');
    if (!$stmtDisciplinaCurso) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a disciplina no curso', $conn));
    }
    $stmtDisciplinaCurso->bind_param('ii', $idCurso, $idDisciplina);
    $stmtDisciplinaCurso->execute();
    $disciplinaNoCurso = $stmtDisciplinaCurso->get_result()->fetch_assoc();
    $stmtDisciplinaCurso->close();
    if (!$disciplinaNoCurso) {
      redirectWithMessage('error', 'A disciplina selecionada não pertence ao curso escolhido.');
    }

    $stmt = $conn->prepare('INSERT INTO notas (IdAluno, IdDisciplina, Nota, AnoLetivo, Epoca, DataLancamento) VALUES (?, ?, ?, ?, ?, NOW())');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('registar a nota', $conn));
    }
    $stmt->bind_param('iidss', $idAluno, $idDisciplina, $nota, $anoLetivo, $epoca);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('registar a nota', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Nota registada com sucesso.' : $erroDb);
  }

  if ($postAction === 'update') {
    $idNota = (int)($_POST['IdNota'] ?? 0);
    $idCurso = (int)($_POST['IdCurso'] ?? 0);
    $idAluno = (int)($_POST['IdAluno'] ?? 0);
    $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
    $notaRaw = $_POST['Nota'] ?? '';
    $nota = -1;
    $anoLetivo = trim((string)($_POST['AnoLetivo'] ?? ''));
    $epoca = normalizarEpoca($_POST['Epoca'] ?? '');

    $anoLetivoValido = anoLetivoValido($anoLetivo);

    if (!parseNota($notaRaw, $nota) || $idNota <= 0 || $idCurso <= 0 || $idAluno <= 0 || $idDisciplina <= 0 || !$anoLetivoValido || $epoca === '') {
      redirectWithMessage('error', 'Dados inválidos para atualização da nota (nota: 0 a 20, ano letivo: YYYY/YYYY, época obrigatória).');
    }

    $stmtNotaExiste = $conn->prepare('SELECT IdNota FROM notas WHERE IdNota = ? LIMIT 1');
    if (!$stmtNotaExiste) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a nota', $conn));
    }
    $stmtNotaExiste->bind_param('i', $idNota);
    $stmtNotaExiste->execute();
    $notaExiste = $stmtNotaExiste->get_result()->fetch_assoc();
    $stmtNotaExiste->close();
    if (!$notaExiste) {
      redirectWithMessage('error', 'A nota que tentou atualizar já não existe.');
    }

    $stmtAlunoCurso = $conn->prepare("SELECT 1 FROM matriculas WHERE IdCurso = ? AND IdAluno = ? AND LOWER(COALESCE(Status, '')) = 'aceite' LIMIT 1");
    if (!$stmtAlunoCurso) {
      redirectWithMessage('error', mensagemErroBaseDados('validar o aluno no curso', $conn));
    }
    $stmtAlunoCurso->bind_param('ii', $idCurso, $idAluno);
    $stmtAlunoCurso->execute();
    $alunoNoCurso = $stmtAlunoCurso->get_result()->fetch_assoc();
    $stmtAlunoCurso->close();
    if (!$alunoNoCurso) {
      redirectWithMessage('error', 'O aluno selecionado não pertence ao curso escolhido.');
    }

    $stmtDisciplinaCurso = $conn->prepare('SELECT 1 FROM plano_estudos WHERE IdCurso = ? AND IdDisciplina = ? LIMIT 1');
    if (!$stmtDisciplinaCurso) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a disciplina no curso', $conn));
    }
    $stmtDisciplinaCurso->bind_param('ii', $idCurso, $idDisciplina);
    $stmtDisciplinaCurso->execute();
    $disciplinaNoCurso = $stmtDisciplinaCurso->get_result()->fetch_assoc();
    $stmtDisciplinaCurso->close();
    if (!$disciplinaNoCurso) {
      redirectWithMessage('error', 'A disciplina selecionada não pertence ao curso escolhido.');
    }

    $stmt = $conn->prepare('UPDATE notas SET IdAluno = ?, IdDisciplina = ?, Nota = ?, AnoLetivo = ?, Epoca = ? WHERE IdNota = ?');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('atualizar a nota', $conn));
    }
    $stmt->bind_param('iidssi', $idAluno, $idDisciplina, $nota, $anoLetivo, $epoca, $idNota);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('atualizar a nota', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Nota atualizada com sucesso.' : $erroDb);
  }

  if ($postAction === 'delete') {
    $idNota = (int)($_POST['IdNota'] ?? 0);

    if ($idNota <= 0) {
      redirectWithMessage('error', 'Nota inválida para remoção.');
    }

    $stmtExiste = $conn->prepare('SELECT IdNota FROM notas WHERE IdNota = ? LIMIT 1');
    if (!$stmtExiste) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a nota', $conn));
    }
    $stmtExiste->bind_param('i', $idNota);
    $stmtExiste->execute();
    $notaExiste = $stmtExiste->get_result()->fetch_assoc();
    $stmtExiste->close();
    if (!$notaExiste) {
      redirectWithMessage('error', 'A nota que tentou remover já não existe.');
    }

    $stmt = $conn->prepare('DELETE FROM notas WHERE IdNota = ?');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('remover a nota', $conn));
    }
    $stmt->bind_param('i', $idNota);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('remover a nota', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Nota removida com sucesso.' : $erroDb);
  }
}

$action = $_GET['action'] ?? 'list';
$acoesGetPermitidas = ['list', 'edit'];
if (!in_array($action, $acoesGetPermitidas, true)) {
  redirectWithMessage('error', 'Ação inválida.');
}
$message = $_GET['message'] ?? '';
$type = $_GET['type'] ?? '';
$editData = null;

if ($action === 'edit') {
  $idNota = (int)($_GET['id_nota'] ?? 0);
  if ($idNota <= 0) {
    redirectWithMessage('error', 'Nota inválida para edição.');
  }
  $stmt = $conn->prepare('SELECT * FROM notas WHERE IdNota = ?');
  if (!$stmt) {
    redirectWithMessage('error', mensagemErroBaseDados('obter a nota', $conn));
  }
  $stmt->bind_param('i', $idNota);
  $stmt->execute();
  $editData = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$editData) {
    redirectWithMessage('error', 'A nota selecionada não foi encontrada.');
  }
}

$disciplinasLookup = fetchLookup($conn, 'disciplina', 'IdDisciplina', 'Disciplina');
$cursosLookup = fetchLookup($conn, 'cursos', 'IdCurso', 'Curso');
$alunosLookup = [];
$resultAlunos = $conn->query("SELECT DISTINCT m.IdAluno, COALESCE(fa.nome, u.login, m.Nome) AS Nome FROM matriculas m LEFT JOIN users u ON u.IdUser = m.IdAluno LEFT JOIN ficha_aluno fa ON fa.IdUser = m.IdAluno WHERE m.IdAluno > 0 AND LOWER(COALESCE(m.Status, '')) = 'aceite' ORDER BY Nome");
if ($resultAlunos) {
  while ($row = $resultAlunos->fetch_assoc()) {
    $alunosLookup[] = $row;
  }
}

$cursosAlunosMap = [];
$resultCursosAlunos = $conn->query(
  "SELECT DISTINCT IdCurso, IdAluno
   FROM matriculas
  WHERE IdCurso IS NOT NULL AND IdAluno IS NOT NULL AND LOWER(COALESCE(Status, '')) = 'aceite'"
);
if ($resultCursosAlunos) {
  while ($row = $resultCursosAlunos->fetch_assoc()) {
    $idCursoMap = (int)$row['IdCurso'];
    $idAlunoMap = (int)$row['IdAluno'];
    if (!isset($cursosAlunosMap[$idCursoMap])) {
      $cursosAlunosMap[$idCursoMap] = [];
    }
    if (!in_array($idAlunoMap, $cursosAlunosMap[$idCursoMap], true)) {
      $cursosAlunosMap[$idCursoMap][] = $idAlunoMap;
    }
  }
}

$cursosDisciplinasMap = [];
$resultCursosDisciplinas = $conn->query(
  "SELECT DISTINCT IdCurso, IdDisciplina
   FROM plano_estudos
   WHERE IdCurso IS NOT NULL AND IdDisciplina IS NOT NULL"
);
if ($resultCursosDisciplinas) {
  while ($row = $resultCursosDisciplinas->fetch_assoc()) {
    $idCursoMap = (int)$row['IdCurso'];
    $idDisciplinaMap = (int)$row['IdDisciplina'];
    if (!isset($cursosDisciplinasMap[$idCursoMap])) {
      $cursosDisciplinasMap[$idCursoMap] = [];
    }
    if (!in_array($idDisciplinaMap, $cursosDisciplinasMap[$idCursoMap], true)) {
      $cursosDisciplinasMap[$idCursoMap][] = $idDisciplinaMap;
    }
  }
}

$idCursoNotaForm = 0;
if ($editData) {
  $idAlunoEdit = (int)($editData['IdAluno'] ?? 0);
  $idDisciplinaEdit = (int)($editData['IdDisciplina'] ?? 0);
  foreach ($cursosAlunosMap as $idCursoMap => $alunosDoCurso) {
    if (in_array($idAlunoEdit, $alunosDoCurso, true)) {
      $idCursoNotaForm = (int)$idCursoMap;
      break;
    }
  }
  if ($idCursoNotaForm === 0) {
    foreach ($cursosDisciplinasMap as $idCursoMap => $disciplinasDoCurso) {
      if (in_array($idDisciplinaEdit, $disciplinasDoCurso, true)) {
        $idCursoNotaForm = (int)$idCursoMap;
        break;
      }
    }
  }
}

$pautaCursoId = (int)($_GET['pauta_id_curso'] ?? 0);
$pautaAlunoId = (int)($_GET['pauta_id_aluno'] ?? 0);
$pautaDisciplinaId = (int)($_GET['pauta_id_disciplina'] ?? 0);
$pautaAnoLetivo = trim((string)($_GET['pauta_ano_letivo'] ?? ''));
$pautaEpoca = normalizarEpoca($_GET['pauta_epoca'] ?? '');

if ($pautaAnoLetivo !== '' && !anoLetivoValido($pautaAnoLetivo)) {
  $pautaAnoLetivo = '';
}

if ($pautaCursoId <= 0) {
  $pautaAlunoId = 0;
  $pautaDisciplinaId = 0;
} else {
  $alunosPermitidosPauta = $cursosAlunosMap[$pautaCursoId] ?? [];
  if ($pautaAlunoId > 0 && !in_array($pautaAlunoId, $alunosPermitidosPauta, true)) {
    $pautaAlunoId = 0;
  }

  $disciplinasPermitidasPauta = $cursosDisciplinasMap[$pautaCursoId] ?? [];
  if ($pautaDisciplinaId > 0 && !in_array($pautaDisciplinaId, $disciplinasPermitidasPauta, true)) {
    $pautaDisciplinaId = 0;
  }
}

$pautaRows = [];
if ($pautaCursoId > 0) {
  $stmtPauta = $conn->prepare(
    "SELECT n.IdNota, n.IdAluno, n.Nota, n.AnoLetivo, n.Epoca, n.DataLancamento, COALESCE(fa.nome, u.login) AS NomeAluno, u.login, d.Disciplina, c.Curso
     FROM notas n
     JOIN users u ON u.IdUser = n.IdAluno
     LEFT JOIN ficha_aluno fa ON fa.IdUser = n.IdAluno
     JOIN disciplina d ON d.IdDisciplina = n.IdDisciplina
     JOIN matriculas m ON m.IdAluno = n.IdAluno
     JOIN cursos c ON c.IdCurso = m.IdCurso
     WHERE m.IdCurso = ?
      AND LOWER(COALESCE(m.Status, '')) = 'aceite'
      AND (? = 0 OR n.IdAluno = ?)
      AND (? = 0 OR n.IdDisciplina = ?)
      AND (? = '' OR n.AnoLetivo = ?)
      AND (? = '' OR n.Epoca = ?)
     ORDER BY n.IdAluno"
  );
    $stmtPauta->bind_param('iiiiissss', $pautaCursoId, $pautaAlunoId, $pautaAlunoId, $pautaDisciplinaId, $pautaDisciplinaId, $pautaAnoLetivo, $pautaAnoLetivo, $pautaEpoca, $pautaEpoca);
  $stmtPauta->execute();
  $resultPauta = $stmtPauta->get_result();
  while ($row = $resultPauta->fetch_assoc()) {
    $pautaRows[] = $row;
  }
  $stmtPauta->close();
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Notas - IPCA VNF</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
  <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
  <script src="../js/notas-transfer.js" defer></script>
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1>Notas e Pautas</h1>
      <p>Gestão de avaliações.</p>
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

    <?php if (!$isStaffAcademico): ?>
      <div class="form-box">
        <p>Sem permissões para gerir notas.</p>
      </div>
    <?php else: ?>
      <div class="form-box">
        <h3><?php echo ($action === 'edit' && $editData) ? 'Editar Nota' : 'Registar Nota'; ?></h3>
        <form method="post">
          <input type="hidden" name="action" value="<?php echo ($action === 'edit' && $editData) ? 'update' : 'create'; ?>">
          <?php if ($action === 'edit' && $editData): ?>
            <input type="hidden" name="IdNota" value="<?php echo e($editData['IdNota']); ?>">
          <?php endif; ?>

          <?php
            $cursosAlunosJson = json_encode($cursosAlunosMap);
            $cursosDisciplinasJson = json_encode($cursosDisciplinasMap);
            $anosLetivosOpcoes = gerarOpcoesAnoLetivo();
            $anoLetivoAtualForm = trim((string)($editData['AnoLetivo'] ?? ''));
            if ($anoLetivoAtualForm !== '' && !in_array($anoLetivoAtualForm, $anosLetivosOpcoes, true)) {
              $anosLetivosOpcoes[] = $anoLetivoAtualForm;
            }
          ?>

          <label for="nota_IdCurso">Curso</label>
          <select id="nota_IdCurso" name="IdCurso" required>
            <option value="">Selecione</option>
            <?php foreach ($cursosLookup as $cursoItem): ?>
              <option value="<?php echo e($cursoItem['IdCurso']); ?>" <?php echo $idCursoNotaForm === (int)$cursoItem['IdCurso'] ? 'selected' : ''; ?>><?php echo e($cursoItem['Curso']); ?></option>
            <?php endforeach; ?>
          </select>

          <div id="nota_grupo_aluno" style="display:none;">
          <label for="nota_IdAluno">Aluno</label>
          <select id="nota_IdAluno" name="IdAluno" required>
            <option value="">Selecione</option>
            <?php foreach ($alunosLookup as $alunoItem): ?>
              <option value="<?php echo e($alunoItem['IdAluno']); ?>" <?php echo (int)($editData['IdAluno'] ?? 0) === (int)$alunoItem['IdAluno'] ? 'selected' : ''; ?>>
                <?php echo e($alunoItem['IdAluno'] . ' - ' . $alunoItem['Nome']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          </div>

          <div id="nota_grupo_disciplina" style="display:none;">
          <label for="nota_IdDisciplina">Unidade Curricular</label>
          <select id="nota_IdDisciplina" name="IdDisciplina" required>
            <option value="">Selecione</option>
            <?php foreach ($disciplinasLookup as $disciplinaItem): ?>
              <option value="<?php echo e($disciplinaItem['IdDisciplina']); ?>" <?php echo (int)($editData['IdDisciplina'] ?? 0) === (int)$disciplinaItem['IdDisciplina'] ? 'selected' : ''; ?>>
                <?php echo e($disciplinaItem['Disciplina']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          </div>

          <label>Nota (0 a 20)</label>
          <input name="Nota" type="number" min="0" max="20" step="0.01" required value="<?php echo e($editData['Nota'] ?? ''); ?>">

          <label for="nota_AnoLetivo">Ano letivo</label>
          <select id="nota_AnoLetivo" name="AnoLetivo" required>
            <option value="">Selecione</option>
            <?php foreach ($anosLetivosOpcoes as $anoLetivoOpcao): ?>
              <option value="<?php echo e($anoLetivoOpcao); ?>" <?php echo $anoLetivoAtualForm === $anoLetivoOpcao ? 'selected' : ''; ?>>
                <?php echo e($anoLetivoOpcao); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label for="nota_Epoca">Época</label>
          <select id="nota_Epoca" name="Epoca" required>
            <?php $epocaAtual = normalizarEpoca($editData['Epoca'] ?? 'Normal'); ?>
            <option value="">Selecione</option>
            <option value="Normal" <?php echo $epocaAtual === 'Normal' ? 'selected' : ''; ?>>Normal</option>
            <option value="Recurso" <?php echo $epocaAtual === 'Recurso' ? 'selected' : ''; ?>>Recurso</option>
            <option value="Especial" <?php echo $epocaAtual === 'Especial' ? 'selected' : ''; ?>>Especial</option>
          </select>

          <button type="submit"><?php echo ($action === 'edit' && $editData) ? 'Atualizar nota' : 'Registar nota'; ?></button>
          <?php if ($action === 'edit'): ?>
            <a class="back-link" href="notas.php">Cancelar edição</a>
          <?php endif; ?>
        </form>

        <script>
          var cursosAlunosMap = <?php echo $cursosAlunosJson; ?>;
          var cursosDisciplinasMap = <?php echo $cursosDisciplinasJson; ?>;
          var todosAlunosOptions = [];
          var todasDisciplinasOptions = [];

          function repopularSelect(selectEl, sourceOptions, idsPermitidos, placeholder, valorAtual) {
            selectEl.innerHTML = '';

            var defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = placeholder;
            selectEl.appendChild(defaultOption);

            var idsSet = {};
            idsPermitidos.forEach(function(id) {
              idsSet[String(id)] = true;
            });

            sourceOptions.forEach(function(optData) {
              if (!idsSet[String(optData.value)]) {
                return;
              }
              var opt = document.createElement('option');
              opt.value = String(optData.value);
              opt.textContent = optData.text;
              if (String(valorAtual) === String(optData.value)) {
                opt.selected = true;
              }
              selectEl.appendChild(opt);
            });
          }

          function filtrarPorCursoNotas() {
            var cursoId = document.getElementById('nota_IdCurso').value;
            var grupoAluno = document.getElementById('nota_grupo_aluno');
            var grupoDisciplina = document.getElementById('nota_grupo_disciplina');
            var selAluno = document.getElementById('nota_IdAluno');
            var selDisciplina = document.getElementById('nota_IdDisciplina');

            if (!cursoId) {
              grupoAluno.style.display = 'none';
              grupoDisciplina.style.display = 'none';
              selAluno.value = '';
              selDisciplina.value = '';
              return;
            }

            grupoAluno.style.display = 'block';
            grupoDisciplina.style.display = 'block';

            var alunosDisponiveis = cursosAlunosMap[cursoId] || [];
            var valorAlunoAtual = selAluno.value;
            repopularSelect(selAluno, todosAlunosOptions, alunosDisponiveis, 'Selecione', valorAlunoAtual);

            var disciplinasDisponiveis = cursosDisciplinasMap[cursoId] || [];
            var valorDisciplinaAtual = selDisciplina.value;
            repopularSelect(selDisciplina, todasDisciplinasOptions, disciplinasDisponiveis, 'Selecione', valorDisciplinaAtual);
          }

          document.addEventListener('DOMContentLoaded', function() {
            var selAluno = document.getElementById('nota_IdAluno');
            var selDisciplina = document.getElementById('nota_IdDisciplina');

            Array.prototype.forEach.call(selAluno.options, function(opt) {
              if (!opt.value) return;
              todosAlunosOptions.push({ value: parseInt(opt.value, 10), text: opt.textContent });
            });

            Array.prototype.forEach.call(selDisciplina.options, function(opt) {
              if (!opt.value) return;
              todasDisciplinasOptions.push({ value: parseInt(opt.value, 10), text: opt.textContent });
            });

            document.getElementById('nota_IdCurso').addEventListener('change', filtrarPorCursoNotas);
            filtrarPorCursoNotas();
          });
        </script>
      </div>

      <div class="form-box">
        <h3>Gerar pauta de avaliação</h3>
        <form method="get" class="filter-form">
          <label for="pauta_id_curso" class="filter-label">Curso</label>
          <select id="pauta_id_curso" name="pauta_id_curso" required>
            <option value="">Selecione</option>
            <?php foreach ($cursosLookup as $cursoItem): ?>
              <option value="<?php echo e($cursoItem['IdCurso']); ?>" <?php echo $pautaCursoId === (int)$cursoItem['IdCurso'] ? 'selected' : ''; ?>>
                <?php echo e($cursoItem['Curso']); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <div id="pauta_grupo_aluno" style="display:none;">
            <label for="pauta_id_aluno" class="filter-label">Aluno</label>
            <select id="pauta_id_aluno" name="pauta_id_aluno">
              <option value="0">Todos os alunos</option>
              <?php foreach ($alunosLookup as $alunoItem): ?>
                <option value="<?php echo e($alunoItem['IdAluno']); ?>" <?php echo $pautaAlunoId === (int)$alunoItem['IdAluno'] ? 'selected' : ''; ?>>
                  <?php echo e($alunoItem['IdAluno'] . ' - ' . $alunoItem['Nome']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div id="pauta_grupo_disciplina" style="display:none;">
            <label for="pauta_id_disciplina" class="filter-label">Unidade Curricular</label>
            <select id="pauta_id_disciplina" name="pauta_id_disciplina">
              <option value="0">Todas as unidades curriculares</option>
              <?php foreach ($disciplinasLookup as $disciplinaItem): ?>
                <option value="<?php echo e($disciplinaItem['IdDisciplina']); ?>" <?php echo $pautaDisciplinaId === (int)$disciplinaItem['IdDisciplina'] ? 'selected' : ''; ?>>
                  <?php echo e($disciplinaItem['Disciplina']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit">Gerar pauta</button>
        </form>

        <script>
          (function() {
            function repopularSelectComTodos(selectEl, sourceOptions, idsPermitidos, placeholderTodos, valorAtual) {
              selectEl.innerHTML = '';

              var defaultOption = document.createElement('option');
              defaultOption.value = '0';
              defaultOption.textContent = placeholderTodos;
              selectEl.appendChild(defaultOption);

              var idsSet = {};
              idsPermitidos.forEach(function(id) {
                idsSet[String(id)] = true;
              });

              sourceOptions.forEach(function(optData) {
                if (!idsSet[String(optData.value)]) {
                  return;
                }
                var opt = document.createElement('option');
                opt.value = String(optData.value);
                opt.textContent = optData.text;
                if (String(valorAtual) === String(optData.value)) {
                  opt.selected = true;
                }
                selectEl.appendChild(opt);
              });
            }

            function filtrarPautaPorCurso() {
              var cursoId = document.getElementById('pauta_id_curso').value;
              var grupoAluno = document.getElementById('pauta_grupo_aluno');
              var grupoDisciplina = document.getElementById('pauta_grupo_disciplina');
              var selAluno = document.getElementById('pauta_id_aluno');
              var selDisciplina = document.getElementById('pauta_id_disciplina');

              if (!cursoId) {
                grupoAluno.style.display = 'none';
                grupoDisciplina.style.display = 'none';
                selAluno.value = '0';
                selDisciplina.value = '0';
                return;
              }

              grupoAluno.style.display = 'block';
              grupoDisciplina.style.display = 'block';

              var alunosDisponiveis = cursosAlunosMap[cursoId] || [];
              var disciplinasDisponiveis = cursosDisciplinasMap[cursoId] || [];

              var valorAlunoAtual = selAluno.value;
              repopularSelectComTodos(selAluno, todosAlunosOptions, alunosDisponiveis, 'Todos os alunos', valorAlunoAtual);
              if (!selAluno.value) {
                selAluno.value = '0';
              }

              var valorDisciplinaAtual = selDisciplina.value;
              repopularSelectComTodos(selDisciplina, todasDisciplinasOptions, disciplinasDisponiveis, 'Todas as unidades curriculares', valorDisciplinaAtual);
              if (!selDisciplina.value) {
                selDisciplina.value = '0';
              }
            }

            document.addEventListener('DOMContentLoaded', function() {
              var pautaCurso = document.getElementById('pauta_id_curso');
              if (!pautaCurso) {
                return;
              }
              pautaCurso.addEventListener('change', filtrarPautaPorCurso);
              filtrarPautaPorCurso();
            });
          })();
        </script>

        <?php if ($pautaCursoId > 0): ?>
          <?php if (!empty($pautaRows)): ?>
            <?php
              $payloadPauta = [
                'curso_id' => $pautaCursoId,
                'aluno_id' => $pautaAlunoId,
                'disciplina_id' => $pautaDisciplinaId,
                'ano_letivo' => $pautaAnoLetivo,
                'epoca' => $pautaEpoca,
                'rows' => []
              ];

              foreach ($pautaRows as $rowNotaItem) {
                $notaLinha = (float)$rowNotaItem['Nota'];
                $payloadPauta['rows'][] = [
                  'id_nota' => (int)$rowNotaItem['IdNota'],
                  'id_aluno' => (int)$rowNotaItem['IdAluno'],
                  'aluno' => trim((string)($rowNotaItem['NomeAluno'] ?? '')) !== '' ? (string)$rowNotaItem['NomeAluno'] : (string)$rowNotaItem['login'],
                  'curso' => (string)($rowNotaItem['Curso'] ?? ''),
                  'disciplina' => (string)($rowNotaItem['Disciplina'] ?? ''),
                  'nota' => $notaLinha,
                  'ano_letivo' => trim((string)($rowNotaItem['AnoLetivo'] ?? '')) !== '' ? (string)$rowNotaItem['AnoLetivo'] : '-',
                  'epoca' => trim((string)($rowNotaItem['Epoca'] ?? '')) !== '' ? (string)$rowNotaItem['Epoca'] : '-',
                  'data_lancamento' => formatarDataHora($rowNotaItem['DataLancamento'] ?? ''),
                  'situacao' => $notaLinha >= 10 ? 'Aprovado' : 'Reprovado',
                ];
              }
            ?>
            <script id="notasPautaData" type="application/json"><?php echo json_encode($payloadPauta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
            <p>
              <button type="button" id="btnExportarPautaPdf">Transferir pauta filtrada (PDF)</button>
            </p>
            <table>
              <tr>
                <th>ID Aluno</th>
                <th>Aluno</th>
                <th>Curso</th>
                <th>Unidade Curricular</th>
                <th>Nota</th>
                <th>Ano letivo</th>
                <th>Época</th>
                <th>Data de lançamento</th>
                <th>Situação</th>
                <th>Ações</th>
              </tr>
              <?php foreach ($pautaRows as $rowNota): ?>
                <?php $notaPauta = (float)$rowNota['Nota']; ?>
                <tr>
                  <td><?php echo e($rowNota['IdAluno']); ?></td>
                  <td><?php echo e(trim((string)($rowNota['NomeAluno'] ?? '')) !== '' ? $rowNota['NomeAluno'] : $rowNota['login']); ?></td>
                  <td><?php echo e($rowNota['Curso']); ?></td>
                  <td><?php echo e($rowNota['Disciplina']); ?></td>
                  <td><?php echo e(number_format($notaPauta, 2, ',', '')); ?></td>
                  <td><?php echo e($rowNota['AnoLetivo'] ?? '-'); ?></td>
                  <td><?php echo e($rowNota['Epoca'] ?? '-'); ?></td>
                  <td><?php echo e(formatarDataHora($rowNota['DataLancamento'] ?? '')); ?></td>
                  <td><?php echo $notaPauta >= 10 ? 'Aprovado' : 'Reprovado'; ?></td>
                  <td class="actions">
                    <a href="notas.php?action=edit&id_nota=<?php echo e($rowNota['IdNota']); ?>">Editar</a>
                    <form class="inline" method="post" onsubmit="return confirm('Remover nota?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="IdNota" value="<?php echo e($rowNota['IdNota']); ?>">
                      <button type="submit">Eliminar</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php else: ?>
            <p>Não existem notas registadas para o curso selecionado.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
<?php $conn->close(); ?>

