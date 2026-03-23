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
  header("Location: cursos.php?type={$type}&message={$message}");
  exit;
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
    redirectWithMessage('error', 'Sem permissões para gerir cursos.');
  }

  $postAction = $_POST['action'] ?? '';
  $acoesPermitidas = ['create', 'update', 'delete'];
  if (!in_array($postAction, $acoesPermitidas, true)) {
    redirectWithMessage('error', 'Ação inválida.');
  }

  if ($postAction === 'create') {
    $curso = trim((string)($_POST['Curso'] ?? ''));
    $sigla = trim((string)($_POST['Sigla'] ?? ''));

    if ($curso === '' || $sigla === '') {
      redirectWithMessage('error', 'Preencha o curso e a sigla.');
    }
    if (mb_strlen($curso) > 30 || mb_strlen($sigla) > 10) {
      redirectWithMessage('error', 'O curso ou a sigla excede o tamanho máximo permitido.');
    }

    $stmt = $conn->prepare('INSERT INTO cursos (Curso, Sigla) VALUES (?, ?)');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('criar o curso', $conn));
    }
    $stmt->bind_param('ss', $curso, $sigla);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('criar o curso', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Curso criado com sucesso.' : $erroDb);
  }

  if ($postAction === 'update') {
    $id = (int)($_POST['IdCurso'] ?? 0);
    $curso = trim((string)($_POST['Curso'] ?? ''));
    $sigla = trim((string)($_POST['Sigla'] ?? ''));

    if ($id <= 0) {
      redirectWithMessage('error', 'Curso inválido para atualização.');
    }
    if ($curso === '' || $sigla === '') {
      redirectWithMessage('error', 'Preencha o curso e a sigla.');
    }
    if (mb_strlen($curso) > 30 || mb_strlen($sigla) > 10) {
      redirectWithMessage('error', 'O curso ou a sigla excede o tamanho máximo permitido.');
    }

    $stmtExiste = $conn->prepare('SELECT IdCurso FROM cursos WHERE IdCurso = ? LIMIT 1');
    if (!$stmtExiste) {
      redirectWithMessage('error', mensagemErroBaseDados('validar o curso', $conn));
    }
    $stmtExiste->bind_param('i', $id);
    $stmtExiste->execute();
    $existe = $stmtExiste->get_result()->fetch_assoc();
    $stmtExiste->close();
    if (!$existe) {
      redirectWithMessage('error', 'O curso selecionado não foi encontrado.');
    }

    $stmt = $conn->prepare('UPDATE cursos SET Curso = ?, Sigla = ? WHERE IdCurso = ?');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('atualizar o curso', $conn));
    }
    $stmt->bind_param('ssi', $curso, $sigla, $id);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('atualizar o curso', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Curso atualizado com sucesso.' : $erroDb);
  }

  if ($postAction === 'delete') {
    $id = (int)($_POST['IdCurso'] ?? 0);

    if ($id <= 0) {
      redirectWithMessage('error', 'Curso inválido para remoção.');
    }

    $stmtExiste = $conn->prepare('SELECT IdCurso FROM cursos WHERE IdCurso = ? LIMIT 1');
    if (!$stmtExiste) {
      redirectWithMessage('error', mensagemErroBaseDados('validar o curso', $conn));
    }
    $stmtExiste->bind_param('i', $id);
    $stmtExiste->execute();
    $existe = $stmtExiste->get_result()->fetch_assoc();
    $stmtExiste->close();
    if (!$existe) {
      redirectWithMessage('error', 'O curso que tentou remover já não existe.');
    }

    $stmt = $conn->prepare('DELETE FROM cursos WHERE IdCurso = ?');
    if (!$stmt) {
      redirectWithMessage('error', mensagemErroBaseDados('remover o curso', $conn));
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $erroDb = $ok ? '' : mensagemErroBaseDados('remover o curso', $conn);
    $stmt->close();
    redirectWithMessage($ok ? 'success' : 'error', $ok ? 'Curso removido com sucesso.' : $erroDb);
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

$disciplinasPorCurso = [];
$rowsDisciplinas = $conn->query('SELECT pe.IdCurso, d.Disciplina, pe.semestre FROM plano_estudos pe JOIN disciplina d ON d.IdDisciplina = pe.IdDisciplina ORDER BY pe.semestre, d.Disciplina');
if ($rowsDisciplinas) {
  while ($rowDisciplina = $rowsDisciplinas->fetch_assoc()) {
    $idCursoDisc = (int)$rowDisciplina['IdCurso'];
    if (!isset($disciplinasPorCurso[$idCursoDisc])) {
      $disciplinasPorCurso[$idCursoDisc] = [];
    }
    $disciplinasPorCurso[$idCursoDisc][] = [
      'Disciplina' => (string)$rowDisciplina['Disciplina'],
      'semestre' => (int)$rowDisciplina['semestre'],
    ];
  }
}

if ($action === 'edit') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) {
    redirectWithMessage('error', 'Curso inválido para edição.');
  }
  $stmt = $conn->prepare('SELECT * FROM cursos WHERE IdCurso = ?');
  if (!$stmt) {
    redirectWithMessage('error', mensagemErroBaseDados('obter o curso', $conn));
  }
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $result = $stmt->get_result();
  $editData = $result->fetch_assoc();
  $stmt->close();
  if (!$editData) {
    redirectWithMessage('error', 'O curso selecionado não foi encontrado.');
  }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cursos - IPCA VNF</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
  <style>
    .curso-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(3, 15, 41, 0.65);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 1000;
    }

    .curso-modal-backdrop.open {
      display: flex;
    }

    .curso-modal {
      width: min(760px, 100%);
      max-height: 80vh;
      overflow: auto;
      border-radius: 14px;
      background: #ffffff;
      box-shadow: 0 20px 40px rgba(5, 12, 28, 0.32);
      padding: 20px;
    }

    .curso-modal h3 {
      margin-top: 0;
    }

    .curso-modal ul {
      margin: 0;
      padding-left: 18px;
    }

    .curso-modal li {
      margin-bottom: 8px;
    }

    .curso-modal-actions {
      margin-top: 14px;
      display: flex;
      justify-content: flex-end;
    }

    .curso-modal-empty {
      opacity: 0.85;
    }
  </style>
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1>Cursos</h1>
      <p>Gestão de cursos disponíveis.</p>
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
        <p>Sem permissões para gerir cursos.</p>
      </div>
    <?php elseif ($action === 'create' || $action === 'edit'): ?>
      <h2><?php echo $action === 'edit' ? 'Editar Curso' : 'Novo Curso'; ?></h2>
      <div class="form-box">
        <form method="post">
          <input type="hidden" name="action" value="<?php echo $action === 'edit' ? 'update' : 'create'; ?>">
          <?php if ($action === 'edit' && $editData): ?>
            <input type="hidden" name="IdCurso" value="<?php echo e($editData['IdCurso']); ?>">
          <?php endif; ?>

          <label>Curso</label><br>
          <input type="text" name="Curso" maxlength="30" required value="<?php echo e($editData['Curso'] ?? ''); ?>"><br>

          <label>Sigla</label><br>
          <input type="text" name="Sigla" maxlength="10" required value="<?php echo e($editData['Sigla'] ?? ''); ?>"><br>

          <button type="submit"><?php echo $action === 'edit' ? 'Atualizar' : 'Criar'; ?></button>
          <a class="back-link" href="cursos.php">Voltar à lista</a>
        </form>
      </div>
    <?php else: ?>
      <p><a class="primary-link" href="cursos.php?action=create">+ Novo curso</a></p>
      <?php $rows = $conn->query('SELECT * FROM cursos ORDER BY IdCurso DESC'); ?>
      <table>
        <tr>
          <th>Curso</th>
          <th>Sigla</th>
          <th>Ações</th>
        </tr>
        <?php while ($row = $rows->fetch_assoc()): ?>
          <tr>
            <td><?php echo e($row['Curso']); ?></td>
            <td><?php echo e($row['Sigla']); ?></td>
            <td class="actions">
              <a href="cursos.php?action=edit&id=<?php echo e($row['IdCurso']); ?>">Editar</a>
              <a href="matriculas.php?id_curso=<?php echo e($row['IdCurso']); ?>">Ver matrículas</a>
              <button
                type="button"
                class="btn-ver-disciplinas"
                data-id-curso="<?php echo e($row['IdCurso']); ?>"
                data-curso="<?php echo e($row['Curso']); ?>"
              >
                Ver unidades curriculares
              </button>
              <form class="inline" method="post" onsubmit="return confirm('Remover curso?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="IdCurso" value="<?php echo e($row['IdCurso']); ?>">
                <button type="submit">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
      </table>

      <div id="cursoModalBackdrop" class="curso-modal-backdrop" aria-hidden="true">
        <div class="curso-modal" role="dialog" aria-modal="true" aria-labelledby="cursoModalTitle">
          <h3 id="cursoModalTitle">Unidades curriculares do curso</h3>
          <div id="cursoModalBody"></div>
          <div class="curso-modal-actions">
            <button type="button" id="cursoModalClose">Fechar</button>
          </div>
        </div>
      </div>

      <script>
        (function () {
          var disciplinasPorCurso = <?php echo json_encode($disciplinasPorCurso, JSON_UNESCAPED_UNICODE); ?>;
          var backdrop = document.getElementById('cursoModalBackdrop');
          var modalTitle = document.getElementById('cursoModalTitle');
          var modalBody = document.getElementById('cursoModalBody');
          var closeBtn = document.getElementById('cursoModalClose');

          function closeModal() {
            backdrop.classList.remove('open');
            backdrop.setAttribute('aria-hidden', 'true');
          }

          function openModal(cursoNome, idCurso) {
            var lista = disciplinasPorCurso[idCurso] || [];
            var html = '';

            modalTitle.textContent = 'Unidades curriculares - ' + cursoNome;

            if (!lista.length) {
              html = '<p class="curso-modal-empty">Este curso ainda não tem unidades curriculares associadas no plano de estudos.</p>';
            } else {
              html = '<ul>';
              for (var i = 0; i < lista.length; i += 1) {
                var item = lista[i];
                html += '<li><strong>Semestre ' + item.semestre + ':</strong> ' + item.Disciplina + '</li>';
              }
              html += '</ul>';
            }

            modalBody.innerHTML = html;
            backdrop.classList.add('open');
            backdrop.setAttribute('aria-hidden', 'false');
          }

          document.querySelectorAll('.btn-ver-disciplinas').forEach(function (btn) {
            btn.addEventListener('click', function () {
              openModal(btn.getAttribute('data-curso') || 'Curso', btn.getAttribute('data-id-curso') || '0');
            });
          });

          closeBtn.addEventListener('click', closeModal);
          backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) {
              closeModal();
            }
          });

          document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && backdrop.classList.contains('open')) {
              closeModal();
            }
          });
        })();
      </script>
    <?php endif; ?>
  </div>
</body>
</html>
<?php $conn->close(); ?>

