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
$tipoUtilizador = 'Aluno';

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

function e($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$cursosComPlano = [];
$resultCursosPlano = $conn->query(
  "SELECT c.Curso, d.Disciplina, pe.semestre
   FROM cursos c
   LEFT JOIN plano_estudos pe ON pe.IdCurso = c.IdCurso
   LEFT JOIN disciplina d ON d.IdDisciplina = pe.IdDisciplina
   ORDER BY c.Curso, pe.semestre, d.Disciplina"
);

if ($resultCursosPlano) {
  while ($row = $resultCursosPlano->fetch_assoc()) {
    $nomeCurso = (string)$row['Curso'];
    if (!isset($cursosComPlano[$nomeCurso])) {
      $cursosComPlano[$nomeCurso] = [];
    }

    if (!empty($row['Disciplina'])) {
      $semestre = (int)($row['semestre'] ?? 0);
      if ($semestre <= 0) {
        $semestre = 1;
      }
      $cursosComPlano[$nomeCurso][] = [
        'Disciplina' => (string)$row['Disciplina'],
        'Semestre' => $semestre,
      ];
    }
  }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cursos e Plano de Estudos - IPCA</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1>Cursos e Plano de Estudos</h1>
      <p>Consulta todos os cursos com unidades curriculares e semestre.</p>
      <span class="role-badge">Tipo de utilizador: <?php echo e($tipoUtilizador); ?></span>
    </header>

    <nav>
      <a href="aluno.php">Minha Area</a>
      <a href="aluno_cursos.php">Cursos</a>
      <a href="ficha_aluno.php">Ficha de Aluno</a>
      <a href="aluno_notas.php">Minhas Notas</a>
      <a href="?action=logout">Terminar sessao</a>
    </nav>

    <?php if (!empty($cursosComPlano)): ?>
      <?php foreach ($cursosComPlano as $cursoNome => $disciplinas): ?>
        <div class="form-box">
          <h3><?php echo e($cursoNome); ?></h3>
          <?php if (!empty($disciplinas)): ?>
            <table>
              <tr>
                <th>Unidade Curricular</th>
                <th>Semestre</th>
              </tr>
              <?php foreach ($disciplinas as $disc): ?>
                <tr>
                  <td><?php echo e($disc['Disciplina']); ?></td>
                  <td><?php echo e($disc['Semestre']); ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php else: ?>
            <p>Este curso ainda nao tem unidades curriculares associadas.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="form-box">
        <p>Não existem cursos com plano de estudos definido.</p>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
<?php $conn->close(); ?>
