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

function redirectWithMessage($type, $message)
{
  $type = urlencode($type);
  $message = urlencode($message);
  header("Location: planos.php?type={$type}&message={$message}");
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
$tipoUtilizador = $isAluno ? 'Aluno' : ($isFuncionario ? 'Funcionario' : 'Gestor');

if ($isAluno) {
  header('Location: aluno.php');
  exit;
}
if ($isFuncionario) {
  header('Location: matriculas.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$isGestor) {
    redirectWithMessage('error', 'Sem permissões para criar planos de estudo.');
  }

  $postAction = $_POST['action'] ?? '';
  if ($postAction !== 'create') {
    redirectWithMessage('error', 'Ação inválida.');
  }

  $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
  $idCurso = (int)($_POST['IdCurso'] ?? 0);
  $semestre = (int)($_POST['Semestre'] ?? 0);

  if ($idDisciplina <= 0 || $idCurso <= 0 || !in_array($semestre, [1, 2], true)) {
    redirectWithMessage('error', 'Dados inválidos. O semestre deve ser 1 ou 2.');
  }

  $stmtDisciplina = $conn->prepare('SELECT IdDisciplina FROM disciplina WHERE IdDisciplina = ? LIMIT 1');
  if (!$stmtDisciplina) {
    redirectWithMessage('error', mensagemErroBaseDados('validar a disciplina', $conn));
  }
  $stmtDisciplina->bind_param('i', $idDisciplina);
  $stmtDisciplina->execute();
  $disciplinaExiste = $stmtDisciplina->get_result()->fetch_assoc();
  $stmtDisciplina->close();
  if (!$disciplinaExiste) {
    redirectWithMessage('error', 'A disciplina selecionada não existe.');
  }

  $stmtCurso = $conn->prepare('SELECT IdCurso FROM cursos WHERE IdCurso = ? LIMIT 1');
  if (!$stmtCurso) {
    redirectWithMessage('error', mensagemErroBaseDados('validar o curso', $conn));
  }
  $stmtCurso->bind_param('i', $idCurso);
  $stmtCurso->execute();
  $cursoExiste = $stmtCurso->get_result()->fetch_assoc();
  $stmtCurso->close();
  if (!$cursoExiste) {
    redirectWithMessage('error', 'O curso selecionado não existe.');
  }

  $stmtDup = $conn->prepare('SELECT 1 FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ? AND semestre = ? LIMIT 1');
  if (!$stmtDup) {
    redirectWithMessage('error', mensagemErroBaseDados('validar a ligação existente', $conn));
  }
  $stmtDup->bind_param('iii', $idDisciplina, $idCurso, $semestre);
  $stmtDup->execute();
  $dup = $stmtDup->get_result()->fetch_assoc();
  $stmtDup->close();
  if ($dup) {
    redirectWithMessage('error', 'Esta UC já está associada a este curso no mesmo semestre.');
  }

  $stmt = $conn->prepare('INSERT INTO plano_estudos (IdDisciplina, IdCurso, semestre) VALUES (?, ?, ?)');
  if (!$stmt) {
    redirectWithMessage('error', mensagemErroBaseDados('criar o plano de estudo', $conn));
  }
  $stmt->bind_param('iii', $idDisciplina, $idCurso, $semestre);
  $ok = $stmt->execute();
  $erroDb = $ok ? '' : mensagemErroBaseDados('criar o plano de estudo', $conn);
  $stmt->close();

  redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Plano de estudo criado com sucesso.' : $erroDb);
}

$disciplinasLookup = fetchLookup($conn, 'disciplina', 'IdDisciplina', 'Disciplina');
$cursosLookup = fetchLookup($conn, 'cursos', 'IdCurso', 'Curso');

$message = $_GET['message'] ?? '';
$type = $_GET['type'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Planos de Estudo - IPCA VNF</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
  <style>
    .planos-acoes {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .planos-acoes .primary-link {
      margin: 0;
    }
  </style>
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1>Plano de Estudos</h1>
      <p>Gestão das ligações curso-unidade curricular por semestre.</p>
      <span class="role-badge">Tipo de utilizador: <?php echo e($tipoUtilizador); ?></span>
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
        <h3>Edição de planos de estudo</h3>
        <div class="planos-acoes">
          <a class="primary-link" href="planos_editar.php">Editar planos de estudo</a>
        </div>
      </div>

      <div class="form-box">
        <h3>Criar plano de estudo</h3>
        <form method="post">
          <input type="hidden" name="action" value="create">

          <label>Curso</label><br>
          <select name="IdCurso" required>
            <option value="">Selecione</option>
            <?php foreach ($cursosLookup as $item): ?>
              <option value="<?php echo e($item['IdCurso']); ?>"><?php echo e($item['Curso']); ?></option>
            <?php endforeach; ?>
          </select><br>

          <label>Unidade Curricular</label><br>
          <select name="IdDisciplina" required>
            <option value="">Selecione</option>
            <?php foreach ($disciplinasLookup as $item): ?>
              <option value="<?php echo e($item['IdDisciplina']); ?>"><?php echo e($item['Disciplina']); ?></option>
            <?php endforeach; ?>
          </select><br>

          <label>Semestre</label><br>
          <select name="Semestre" required>
            <option value="1">1</option>
            <option value="2">2</option>
          </select><br>

          <button type="submit">Criar plano de estudo</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
<?php $conn->close(); ?>

