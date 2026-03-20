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

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ipcapw";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die('Falha na ligação à base de dados: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

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
  header("Location: disciplinas.php?type={$type}&message={$message}");
  exit;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$isGestor) {
    redirectWithMessage('error', 'Sem permissões para gerir unidades curriculares.');
  }

  $postAction = $_POST['action'] ?? '';
  $acoesPermitidas = ['create', 'update', 'delete'];
  if (!in_array($postAction, $acoesPermitidas, true)) {
    redirectWithMessage('error', 'Ação inválida.');
  }

  if ($postAction === 'create') {
    $disciplina = trim((string)($_POST['Disciplina'] ?? ''));
    $sigla = trim((string)($_POST['Sigla'] ?? ''));

    if ($disciplina === '' || $sigla === '') {
      redirectWithMessage('error', 'Preencha a disciplina e a sigla.');
    }
    if (mb_strlen($disciplina) > 30 || mb_strlen($sigla) > 10) {
      redirectWithMessage('error', 'A disciplina ou a sigla excede o tamanho máximo permitido.');
    }

    $stmt = $conn->prepare('INSERT INTO disciplina (Disciplina, Sigla) VALUES (?, ?)');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('criar a disciplina', $conn));
    }
    $stmt->bind_param('ss', $disciplina, $sigla);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('criar a disciplina', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Disciplina criada com sucesso.' : $erroDb);
  }

  if ($postAction === 'update') {
    $id = (int)($_POST['IdDisciplina'] ?? 0);
    $disciplina = trim((string)($_POST['Disciplina'] ?? ''));
    $sigla = trim((string)($_POST['Sigla'] ?? ''));

    if ($id <= 0) {
      redirectWithMessage('error', 'Disciplina inválida para atualização.');
    }
    if ($disciplina === '' || $sigla === '') {
      redirectWithMessage('error', 'Preencha a disciplina e a sigla.');
    }
    if (mb_strlen($disciplina) > 30 || mb_strlen($sigla) > 10) {
      redirectWithMessage('error', 'A disciplina ou a sigla excede o tamanho máximo permitido.');
    }

    $stmtExiste = $conn->prepare('SELECT IdDisciplina FROM disciplina WHERE IdDisciplina = ? LIMIT 1');
    if (!$stmtExiste) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a disciplina', $conn));
    }
    $stmtExiste->bind_param('i', $id);
    $stmtExiste->execute();
    $existe = $stmtExiste->get_result()->fetch_assoc();
    $stmtExiste->close();
    if (!$existe) {
      redirectWithMessage('error', 'A disciplina selecionada não foi encontrada.');
    }

    $stmt = $conn->prepare('UPDATE disciplina SET Disciplina = ?, Sigla = ? WHERE IdDisciplina = ?');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('atualizar a disciplina', $conn));
    }
    $stmt->bind_param('ssi', $disciplina, $sigla, $id);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('atualizar a disciplina', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Disciplina atualizada com sucesso.' : $erroDb);
  }

  if ($postAction === 'delete') {
    $id = (int)($_POST['IdDisciplina'] ?? 0);

    if ($id <= 0) {
      redirectWithMessage('error', 'Disciplina inválida para remoção.');
    }

    $stmtExiste = $conn->prepare('SELECT IdDisciplina FROM disciplina WHERE IdDisciplina = ? LIMIT 1');
    if (!$stmtExiste) {
      redirectWithMessage('error', mensagemErroBaseDados('validar a disciplina', $conn));
    }
    $stmtExiste->bind_param('i', $id);
    $stmtExiste->execute();
    $existe = $stmtExiste->get_result()->fetch_assoc();
    $stmtExiste->close();
    if (!$existe) {
      redirectWithMessage('error', 'A disciplina que tentou remover já não existe.');
    }

    $stmt = $conn->prepare('DELETE FROM disciplina WHERE IdDisciplina = ?');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('remover a disciplina', $conn));
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('remover a disciplina', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Disciplina removida com sucesso.' : $erroDb);
  }
}

$action = $_GET['action'] ?? 'list';
$acoesGetPermitidas = ['list', 'create', 'edit'];
if (!in_array($action, $acoesGetPermitidas, true)) {
  redirectWithMessage('error', 'Ação inválida.');
}
$message = $_GET['message'] ?? '';
$type = $_GET['type'] ?? '';
$editData = null;

if ($action === 'edit') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) {
    redirectWithMessage('error', 'Disciplina inválida para edição.');
  }
  $stmt = $conn->prepare('SELECT * FROM disciplina WHERE IdDisciplina = ?');
  if (!$stmt) {
    redirectWithMessage('error', mensagemErroBaseDados('obter a disciplina', $conn));
  }
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $result = $stmt->get_result();
  $editData = $result->fetch_assoc();
  $stmt->close();
  if (!$editData) {
    redirectWithMessage('error', 'A disciplina selecionada não foi encontrada.');
  }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unidades Curriculares - IPCA VNF</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1>Unidades Curriculares</h1>
      <p>Gestão de unidades curriculares.</p>
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
        <p>Sem permissões para gerir unidades curriculares.</p>
      </div>
    <?php elseif ($action === 'create' || $action === 'edit'): ?>
      <h2><?php echo $action === 'edit' ? 'Editar Unidade Curricular' : 'Nova Unidade Curricular'; ?></h2>
      <div class="form-box">
        <form method="post">
          <input type="hidden" name="action" value="<?php echo $action === 'edit' ? 'update' : 'create'; ?>">
          <?php if ($action === 'edit' && $editData): ?>
            <input type="hidden" name="IdDisciplina" value="<?php echo e($editData['IdDisciplina']); ?>">
          <?php endif; ?>

          <label>Unidade Curricular</label><br>
          <input type="text" name="Disciplina" maxlength="30" required value="<?php echo e($editData['Disciplina'] ?? ''); ?>"><br>

          <label>Sigla</label><br>
          <input type="text" name="Sigla" maxlength="10" required value="<?php echo e($editData['Sigla'] ?? ''); ?>"><br>

          <button type="submit"><?php echo $action === 'edit' ? 'Atualizar' : 'Criar'; ?></button>
          <a class="back-link" href="disciplinas.php">Voltar à lista</a>
        </form>
      </div>
    <?php else: ?>
      <p><a class="primary-link" href="disciplinas.php?action=create">+ Nova unidade curricular</a></p>
      <?php $rows = $conn->query('SELECT * FROM disciplina ORDER BY IdDisciplina DESC'); ?>
      <table>
        <tr>
          <th>Unidade Curricular</th>
          <th>Sigla</th>
          <th>Ações</th>
        </tr>
        <?php while ($row = $rows->fetch_assoc()): ?>
          <tr>
            <td><?php echo e($row['Disciplina']); ?></td>
            <td><?php echo e($row['Sigla']); ?></td>
            <td class="actions">
              <a href="disciplinas.php?action=edit&id=<?php echo e($row['IdDisciplina']); ?>">Editar</a>
              <form class="inline" method="post" onsubmit="return confirm('Remover unidade curricular?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="IdDisciplina" value="<?php echo e($row['IdDisciplina']); ?>">
                <button type="submit">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
      </table>
    <?php endif; ?>
  </div>
</body>
</html>
<?php $conn->close(); ?>

