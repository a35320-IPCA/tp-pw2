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
  return 'Não foi possível ' . $operacao . ' neste momento.';
}

function redirectWithMessage($type, $message)
{
  $type = urlencode($type);
  $message = urlencode($message);
  header("Location: aluno_notas.php?type={$type}&message={$message}");
  exit;
}

$loginSessao = (string)($_SESSION['login'] ?? '');
$stmtUser = $conn->prepare(
  'SELECT u.IdUser, u.login, fa.nome
   FROM users u
   LEFT JOIN ficha_aluno fa ON fa.IdUser = u.IdUser
    WHERE LOWER(u.login) = LOWER(?)
   LIMIT 1'
);
if (!$stmtUser) {
  redirectWithMessage('error', mensagemErroBaseDados('obter os dados do utilizador', $conn));
}
$stmtUser->bind_param('s', $loginSessao);
$stmtUser->execute();
$userSessao = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if (!$userSessao) {
  redirectWithMessage('error', 'Utilizador não encontrado.');
}

$stmtMatriculaAceite = $conn->prepare(
  "SELECT m.IdAluno, m.IdCurso, m.Status, c.Curso
   FROM matriculas m
   JOIN cursos c ON c.IdCurso = m.IdCurso
   WHERE m.IdAluno = ?
     AND LOWER(COALESCE(m.Status, 'aceite')) = 'aceite'
   LIMIT 1"
);
if (!$stmtMatriculaAceite) {
  redirectWithMessage('error', mensagemErroBaseDados('validar a matrícula', $conn));
}
$stmtMatriculaAceite->bind_param('i', $userSessao['IdUser']);
$stmtMatriculaAceite->execute();
$matriculaAceite = $stmtMatriculaAceite->get_result()->fetch_assoc();
$stmtMatriculaAceite->close();

$notasAluno = [];

if ($matriculaAceite) {
  $stmtNotas = $conn->prepare(
    'SELECT d.Disciplina, n.Nota, n.AnoLetivo, n.Epoca, n.DataLancamento
     FROM notas n
     JOIN disciplina d ON d.IdDisciplina = n.IdDisciplina
     JOIN plano_estudos pe ON pe.IdDisciplina = n.IdDisciplina
     WHERE n.IdAluno = ?
       AND pe.IdCurso = ?
     ORDER BY n.DataLancamento DESC, d.Disciplina'
  );
  if (!$stmtNotas) {
    redirectWithMessage('error', mensagemErroBaseDados('obter as notas', $conn));
  }
  $stmtNotas->bind_param('ii', $userSessao['IdUser'], $matriculaAceite['IdCurso']);
  $stmtNotas->execute();
  $resultNotas = $stmtNotas->get_result();
  while ($row = $resultNotas->fetch_assoc()) {
    $notasAluno[] = $row;
  }
  $stmtNotas->close();
}

$message = $_GET['message'] ?? '';
$type = $_GET['type'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Notas do Aluno - IPCA VNF</title>
  <link rel="stylesheet" href="../css/ipca-theme.css">
  <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
  <script src="../js/aluno-notas.js" defer></script>
</head>
<body class="ipca-crud">
  <div class="page-shell">
    <header class="page-hero">
      <h1>As Minhas Notas</h1>
      <p>Consulta as tuas classificações.</p>
    </header>

    <nav>
      <a href="aluno.php">Minha Área</a>
      <a href="aluno_notas.php">Minhas Notas</a>
      <a href="?action=logout">Terminar sessão</a>
    </nav>

    <?php if ($message !== ''): ?>
      <div class="message <?php echo e($type); ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if (!$matriculaAceite): ?>
      <div class="form-box">
        <p>Não tens matrícula aceite. Quando tiveres matrícula aceite, poderás consultar notas.</p>
      </div>
    <?php else: ?>
      <div class="form-box">
        <p><strong>Aluno:</strong> <?php echo e(trim((string)($userSessao['nome'] ?? '')) !== '' ? $userSessao['nome'] : $userSessao['login']); ?></p>
        <p><strong>Curso:</strong> <?php echo e($matriculaAceite['Curso']); ?></p>
      </div>

      <div class="form-box">
        <h3>Notas por unidade curricular</h3>
        <?php if (!empty($notasAluno)): ?>
          <?php
            $dadosNotasJs = [];
            foreach ($notasAluno as $notaItem) {
              $valorNotaItem = (float)$notaItem['Nota'];
              $dadosNotasJs[] = [
                'disciplina' => (string)$notaItem['Disciplina'],
                'nota' => $valorNotaItem,
                'anoLetivo' => trim((string)($notaItem['AnoLetivo'] ?? '')) !== '' ? (string)$notaItem['AnoLetivo'] : '-',
                'epoca' => trim((string)($notaItem['Epoca'] ?? '')) !== '' ? (string)$notaItem['Epoca'] : '-',
                'dataLancamento' => formatarDataHora($notaItem['DataLancamento'] ?? ''),
                'situacao' => $valorNotaItem >= 10 ? 'Aprovado' : 'Reprovado'
              ];
            }
            $payloadNotasJs = [
              'aluno' => trim((string)($userSessao['nome'] ?? '')) !== '' ? (string)$userSessao['nome'] : (string)$userSessao['login'],
              'curso' => (string)$matriculaAceite['Curso'],
              'notas' => $dadosNotasJs
            ];
          ?>
          <script id="alunoNotasData" type="application/json"><?php echo json_encode($payloadNotasJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
          <p>
            <button type="button" id="btnExportarPdf">Transferir notas em PDF</button>
            <button type="button" id="btnCalcularMedia">Calcular média</button>
          </p>
          <p id="mediaNotasResultado" style="display:none;"></p>
          <table id="tabelaNotasAluno">
            <tr>
              <th>Unidade Curricular</th>
              <th>Nota</th>
              <th>Ano letivo</th>
              <th>Época</th>
              <th>Data de Lançamento</th>
              <th>Situação</th>
            </tr>
            <?php foreach ($notasAluno as $nota): ?>
              <?php $valorNota = (float)$nota['Nota']; ?>
              <tr>
                <td><?php echo e($nota['Disciplina']); ?></td>
                <td><?php echo e(number_format($valorNota, 2, ',', '')); ?></td>
                <td><?php echo e(trim((string)($nota['AnoLetivo'] ?? '')) !== '' ? $nota['AnoLetivo'] : '-'); ?></td>
                <td><?php echo e(trim((string)($nota['Epoca'] ?? '')) !== '' ? $nota['Epoca'] : '-'); ?></td>
                <td><?php echo e(formatarDataHora($nota['DataLancamento'] ?? '')); ?></td>
                <td><?php echo $valorNota >= 10 ? 'Aprovado' : 'Reprovado'; ?></td>
              </tr>
            <?php endforeach; ?>
          </table>

        <?php else: ?>
          <p>Ainda não existem notas registadas para as tuas unidades curriculares.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
<?php $conn->close(); ?>

