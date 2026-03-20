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
$conn->query("ALTER TABLE plano_estudos ADD COLUMN IF NOT EXISTS semestre TINYINT NOT NULL DEFAULT 1");
$conn->query('UPDATE plano_estudos SET semestre = 1 WHERE semestre NOT IN (1, 2)');

function e($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mensagemErroBaseDados($operacao, mysqli $conn)
{
  if ((int)$conn->errno === 1062) {
    return 'Já existe um registo com os mesmos dados.';
  }
  return 'Não foi possível ' . $operacao . ' neste momento.';
}

function redirectWithMessage($type, $message, $cursoId = 0)
{
  $type = urlencode($type);
  $message = urlencode($message);
  $queryCurso = $cursoId > 0 ? '&id_curso=' . (int)$cursoId : '';
  header("Location: planos_editar.php?type={$type}&message={$message}{$queryCurso}");
  exit;
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

if ($isAluno) {
  header('Location: aluno.php');
  exit;
}
if ($isFuncionario) {
  header('Location: matriculas.php');
  exit;
}

$idCursoSelecionado = (int)($_GET['id_curso'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$isGestor) {
    redirectWithMessage('error', 'Sem permissões para gerir planos de estudo.', $idCursoSelecionado);
  }

  $postAction = $_POST['action'] ?? '';
  $acoesPermitidas = ['create', 'update', 'delete'];
  if (!in_array($postAction, $acoesPermitidas, true)) {
    redirectWithMessage('error', 'Ação inválida.', $idCursoSelecionado);
  }

  if ($postAction === 'create') {
    $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
    $idCurso = (int)($_POST['IdCurso'] ?? 0);
    $semestre = (int)($_POST['Semestre'] ?? 0);

    if ($idDisciplina <= 0 || $idCurso <= 0 || !in_array($semestre, [1, 2], true)) {
      redirectWithMessage('error', 'Dados inválidos. O semestre deve ser 1 ou 2.', $idCursoSelecionado);
    }

    $stmtDisciplina = $conn->prepare('SELECT IdDisciplina FROM disciplina WHERE IdDisciplina = ? LIMIT 1');
    if (!$stmtDisciplina) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a disciplina', $conn), $idCursoSelecionado);
    }
    $stmtDisciplina->bind_param('i', $idDisciplina);
    $stmtDisciplina->execute();
    $disciplinaExiste = $stmtDisciplina->get_result()->fetch_assoc();
    $stmtDisciplina->close();
    if (!$disciplinaExiste) {
      redirectWithMessage('error', 'A disciplina selecionada não existe.', $idCursoSelecionado);
    }

    $stmtCurso = $conn->prepare('SELECT IdCurso FROM cursos WHERE IdCurso = ? LIMIT 1');
    if (!$stmtCurso) {
      redirectWithMessage('error', mensagemErroBaseDados('validar o curso', $conn), $idCursoSelecionado);
    }
    $stmtCurso->bind_param('i', $idCurso);
    $stmtCurso->execute();
    $cursoExiste = $stmtCurso->get_result()->fetch_assoc();
    $stmtCurso->close();
    if (!$cursoExiste) {
      redirectWithMessage('error', 'O curso selecionado não existe.', $idCursoSelecionado);
    }

    $stmtDup = $conn->prepare('SELECT 1 FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ? AND semestre = ? LIMIT 1');
    if (!$stmtDup) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a ligação existente', $conn), $idCursoSelecionado);
    }
    $stmtDup->bind_param('iii', $idDisciplina, $idCurso, $semestre);
    $stmtDup->execute();
    $dup = $stmtDup->get_result()->fetch_assoc();
    $stmtDup->close();
    if ($dup) {
      redirectWithMessage('error', 'Esta UC já está associada a este curso no mesmo semestre.', $idCursoSelecionado);
    }

    $stmt = $conn->prepare('INSERT INTO plano_estudos (IdDisciplina, IdCurso, semestre) VALUES (?, ?, ?)');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('criar a ligação', $conn), $idCursoSelecionado);
    }
    $stmt->bind_param('iii', $idDisciplina, $idCurso, $semestre);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('criar a ligação', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Ligação criada com sucesso.' : $erroDb, $idCurso);
  }

  if ($postAction === 'update') {
    $oldIdDisciplina = (int)($_POST['old_IdDisciplina'] ?? 0);
    $oldIdCurso = (int)($_POST['old_IdCurso'] ?? 0);
    $oldSemestre = (int)($_POST['old_Semestre'] ?? 0);
    $newIdDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
    $newIdCurso = (int)($_POST['IdCurso'] ?? 0);
    $newSemestre = (int)($_POST['Semestre'] ?? 0);

    if ($oldIdDisciplina <= 0 || $oldIdCurso <= 0 || !in_array($oldSemestre, [1, 2], true) || $newIdDisciplina <= 0 || $newIdCurso <= 0 || !in_array($newSemestre, [1, 2], true)) {
      redirectWithMessage('error', 'Dados inválidos para atualização da ligação.', $idCursoSelecionado);
    }

    $stmtOldExiste = $conn->prepare('SELECT 1 FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ? AND semestre = ? LIMIT 1');
    if (!$stmtOldExiste) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a ligação', $conn), $idCursoSelecionado);
    }
    $stmtOldExiste->bind_param('iii', $oldIdDisciplina, $oldIdCurso, $oldSemestre);
    $stmtOldExiste->execute();
    $oldExiste = $stmtOldExiste->get_result()->fetch_assoc();
    $stmtOldExiste->close();
    if (!$oldExiste) {
      redirectWithMessage('error', 'A ligação que tentou atualizar já não existe.', $idCursoSelecionado);
    }

    if ($oldIdDisciplina !== $newIdDisciplina || $oldIdCurso !== $newIdCurso || $oldSemestre !== $newSemestre) {
      $stmtDup = $conn->prepare('SELECT 1 FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ? AND semestre = ? LIMIT 1');
      if (!$stmtDup) {
        redirectWithMessage('error', mensagemErroBaseDados('validar a ligação existente', $conn), $idCursoSelecionado);
      }
      $stmtDup->bind_param('iii', $newIdDisciplina, $newIdCurso, $newSemestre);
      $stmtDup->execute();
      $dup = $stmtDup->get_result()->fetch_assoc();
      $stmtDup->close();
      if ($dup) {
        redirectWithMessage('error', 'Esta UC já está associada a este curso no mesmo semestre.', $idCursoSelecionado);
      }
    }

    $stmt = $conn->prepare('UPDATE plano_estudos SET IdDisciplina = ?, IdCurso = ?, semestre = ? WHERE IdDisciplina = ? AND IdCurso = ? AND semestre = ?');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('atualizar a ligação', $conn), $idCursoSelecionado);
    }
    $stmt->bind_param('iiiiii', $newIdDisciplina, $newIdCurso, $newSemestre, $oldIdDisciplina, $oldIdCurso, $oldSemestre);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('atualizar a ligação', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Ligação atualizada com sucesso.' : $erroDb, $newIdCurso);
  }

  if ($postAction === 'delete') {
    $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
    $idCurso = (int)($_POST['IdCurso'] ?? 0);
    $semestre = (int)($_POST['Semestre'] ?? 0);

    if ($idDisciplina <= 0 || $idCurso <= 0 || !in_array($semestre, [1, 2], true)) {
      redirectWithMessage('error', 'Dados inválidos para remoção da ligação.', $idCursoSelecionado);
    }

    $stmtExiste = $conn->prepare('SELECT 1 FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ? AND semestre = ? LIMIT 1');
    if (!$stmtExiste) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a ligação', $conn), $idCursoSelecionado);
    }
    $stmtExiste->bind_param('iii', $idDisciplina, $idCurso, $semestre);
    $stmtExiste->execute();
    $existe = $stmtExiste->get_result()->fetch_assoc();
    $stmtExiste->close();
    if (!$existe) {
      redirectWithMessage('error', 'A ligação que tentou remover já não existe.', $idCursoSelecionado);
    }

    $stmt = $conn->prepare('DELETE FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ? AND semestre = ?');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('remover a ligação', $conn), $idCursoSelecionado);
    }
    $stmt->bind_param('iii', $idDisciplina, $idCurso, $semestre);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('remover a ligação', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Ligação removida com sucesso.' : $erroDb, $idCurso);
  }
}

$action = $_GET['view_action'] ?? 'list';
$acoesGetPermitidas = ['list', 'edit'];
if (!in_array($action, $acoesGetPermitidas, true)) {
  redirectWithMessage('error', 'Ação inválida.', $idCursoSelecionado);
}
$message = $_GET['message'] ?? '';
$type = $_GET['type'] ?? '';
$editData = null;

$cursosLookup = fetchLookup($conn, 'cursos', 'IdCurso', 'Curso');
$disciplinasLookup = fetchLookup($conn, 'disciplina', 'IdDisciplina', 'Disciplina');

if ($action === 'edit') {
  $idDisciplina = (int)($_GET['id_disciplina'] ?? 0);
  $idCurso = (int)($_GET['id_curso'] ?? 0);
  $semestre = (int)($_GET['semestre'] ?? 0);
  if ($idDisciplina <= 0 || $idCurso <= 0 || !in_array($semestre, [1, 2], true)) {
    redirectWithMessage('error', 'Ligação inválida para edição.', $idCursoSelecionado);
  }
  $stmt = $conn->prepare('SELECT * FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ? AND semestre = ?');
  if (!$stmt) {
    redirectWithMessage('error', mensagemErroBaseDados('obter a ligação', $conn), $idCursoSelecionado);
  }
  $stmt->bind_param('iii', $idDisciplina, $idCurso, $semestre);
  $stmt->execute();
  $editData = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$editData) {
    redirectWithMessage('error', 'A ligação selecionada não foi encontrada.', $idCursoSelecionado);
  }
  $idCursoSelecionado = $idCurso;
}

$cursoSelecionadoExiste = false;
foreach ($cursosLookup as $cursoItem) {
  if ((int)$cursoItem['IdCurso'] === $idCursoSelecionado) {
    $cursoSelecionadoExiste = true;
    break;
  }
}
if (!$cursoSelecionadoExiste) {
  $idCursoSelecionado = 0;
}

$rows = null;
if ($idCursoSelecionado > 0) {
  $stmtRows = $conn->prepare('SELECT pe.IdDisciplina, pe.IdCurso, pe.semestre, d.Disciplina, c.Curso FROM plano_estudos pe JOIN disciplina d ON d.IdDisciplina = pe.IdDisciplina JOIN cursos c ON c.IdCurso = pe.IdCurso WHERE pe.IdCurso = ? ORDER BY pe.semestre, d.Disciplina');
  if ($stmtRows) {
    $stmtRows->bind_param('i', $idCursoSelecionado);
    $stmtRows->execute();
    $rows = $stmtRows->get_result();
  }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar Planos de Estudo - IPCA VNF</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1>Editar Planos de Estudo</h1>
      <p>Escolha um curso para gerir as unidades curriculares por semestre.</p>
    </header>

    <nav>
      <a href="disciplinas.php">Unidades Curriculares</a>
      <a href="cursos.php">Cursos</a>
      <a href="matriculas.php">Matrículas</a>
      <a href="fichas.php">Fichas de Aluno</a>
      <a href="planos.php">Planos de Estudo</a>
      <a href="notas.php">Notas e Pautas</a>
      <a href="?action=logout">Terminar sessão</a>
    </nav>

    <?php if ($message !== ''): ?>
      <div class="message <?php echo e($type); ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if (!$isGestor): ?>
      <div class="form-box">
        <p>Sem permissões para gerir planos de estudo.</p>
      </div>
    <?php else: ?>
      <div class="form-box">
        <h3>Selecionar curso</h3>
        <form method="get">
          <label>Curso</label><br>
          <select name="id_curso" required>
            <option value="">Selecione</option>
            <?php foreach ($cursosLookup as $item): ?>
              <option value="<?php echo e($item['IdCurso']); ?>" <?php echo $idCursoSelecionado === (int)$item['IdCurso'] ? 'selected' : ''; ?>>
                <?php echo e($item['Curso']); ?>
              </option>
            <?php endforeach; ?>
          </select><br>

          <button type="submit">Ver plano do curso</button>
          <a class="back-link" href="planos.php">Voltar</a>
        </form>
      </div>

      <?php if ($idCursoSelecionado > 0): ?>
        <table>
          <tr>
            <th>Unidade Curricular</th>
            <th>Semestre</th>
            <th>Ações</th>
          </tr>
          <?php if ($rows): ?>
            <?php while ($row = $rows->fetch_assoc()): ?>
              <tr>
                <td><?php echo e($row['Disciplina']); ?></td>
                <td><?php echo e($row['semestre']); ?></td>
                <td class="actions">
                  <a href="planos_editar.php?view_action=edit&id_curso=<?php echo e($row['IdCurso']); ?>&id_disciplina=<?php echo e($row['IdDisciplina']); ?>&semestre=<?php echo e($row['semestre']); ?>">Editar</a>
                  <form class="inline" method="post" onsubmit="return confirm('Remover ligação do plano?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="IdDisciplina" value="<?php echo e($row['IdDisciplina']); ?>">
                    <input type="hidden" name="IdCurso" value="<?php echo e($row['IdCurso']); ?>">
                    <input type="hidden" name="Semestre" value="<?php echo e($row['semestre']); ?>">
                    <button type="submit">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </table>

        <div class="form-box">
          <h3><?php echo $editData ? 'Editar Ligação' : 'Nova Ligação'; ?></h3>
          <form method="post">
            <input type="hidden" name="action" value="<?php echo $editData ? 'update' : 'create'; ?>">
            <input type="hidden" name="IdCurso" value="<?php echo e($idCursoSelecionado); ?>">
            <?php if ($editData): ?>
              <input type="hidden" name="old_IdDisciplina" value="<?php echo e($editData['IdDisciplina']); ?>">
              <input type="hidden" name="old_IdCurso" value="<?php echo e($editData['IdCurso']); ?>">
              <input type="hidden" name="old_Semestre" value="<?php echo e($editData['semestre']); ?>">
            <?php endif; ?>

            <label>Unidade Curricular</label><br>
            <select name="IdDisciplina" required>
              <option value="">Selecione</option>
              <?php foreach ($disciplinasLookup as $item): ?>
                <option value="<?php echo e($item['IdDisciplina']); ?>" <?php echo ((int)($editData['IdDisciplina'] ?? 0) === (int)$item['IdDisciplina']) ? 'selected' : ''; ?>>
                  <?php echo e($item['Disciplina']); ?>
                </option>
              <?php endforeach; ?>
            </select><br>

            <label>Semestre</label><br>
            <select name="Semestre" required>
              <option value="1" <?php echo (int)($editData['semestre'] ?? 1) === 1 ? 'selected' : ''; ?>>1</option>
              <option value="2" <?php echo (int)($editData['semestre'] ?? 1) === 2 ? 'selected' : ''; ?>>2</option>
            </select><br>

            <button type="submit"><?php echo $editData ? 'Atualizar' : 'Criar'; ?></button>
            <?php if ($editData): ?>
              <a class="back-link" href="planos_editar.php?id_curso=<?php echo e($idCursoSelecionado); ?>">Cancelar</a>
            <?php endif; ?>
          </form>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>
<?php
if (isset($stmtRows) && $stmtRows instanceof mysqli_stmt) {
  $stmtRows->close();
}
$conn->close();
?>

