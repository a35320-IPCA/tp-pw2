-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 20-Mar-2026 às 16:47
-- Versão do servidor: 10.4.32-MariaDB
-- versão do PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `ipcapw`
--

-- --------------------------------------------------------

--
-- Estrutura da tabela `cursos`
--

CREATE TABLE `cursos` (
  `IdCurso` int(11) NOT NULL,
  `Curso` varchar(40) NOT NULL,
  `Sigla` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `disciplina`
--

CREATE TABLE `disciplina` (
  `IdDisciplina` int(11) NOT NULL,
  `Disciplina` varchar(50) NOT NULL,
  `Sigla` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `ficha_aluno`
--

CREATE TABLE `ficha_aluno` (
  `nome` varchar(50) NOT NULL,
  `idade` int(3) NOT NULL,
  `telefone` int(11) NOT NULL,
  `morada` varchar(50) NOT NULL,
  `nif` int(11) NOT NULL,
  `data_nascimento` date NOT NULL,
  `foto` blob DEFAULT NULL,
  `IdFichaAluno` int(11) NOT NULL,
  `IdUser` int(11) NOT NULL,
  `Status` varchar(50) NOT NULL DEFAULT 'Rascunho'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `matriculas`
--

CREATE TABLE `matriculas` (
  `IdMatricula` int(11) NOT NULL,
  `IdAluno` int(11) NOT NULL,
  `Nome` varchar(50) NOT NULL,
  `IdCurso` int(11) NOT NULL,
  `Foto` blob DEFAULT NULL,
  `Status` varchar(25) NOT NULL,
  `Data` date NOT NULL,
  `IdFuncionario` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `notas`
--

CREATE TABLE `notas` (
  `IdNota` int(11) NOT NULL,
  `IdAluno` int(11) NOT NULL,
  `IdDisciplina` int(11) NOT NULL,
  `Nota` int(2) NOT NULL,
  `AnoLetivo` varchar(9) NOT NULL,
  `Epoca` enum('Normal','Recurso','Especial') NOT NULL DEFAULT 'Normal',
  `DataLancamento` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `perfis`
--

CREATE TABLE `perfis` (
  `IdPerfil` int(11) NOT NULL,
  `perfil` varchar(25) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `plano_estudos`
--

CREATE TABLE `plano_estudos` (
  `IdPlanoEstudo` int(11) NOT NULL,
  `IdDisciplina` int(11) NOT NULL,
  `IdCurso` int(11) NOT NULL,
  `semestre` int(2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `users`
--

CREATE TABLE `users` (
  `IdUser` int(11) NOT NULL,
  `login` varchar(25) NOT NULL,
  `pwd` varchar(255) NOT NULL,
  `Idperfil` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices para tabela `cursos`
--
ALTER TABLE `cursos`
  ADD PRIMARY KEY (`IdCurso`);

--
-- Índices para tabela `disciplina`
--
ALTER TABLE `disciplina`
  ADD PRIMARY KEY (`IdDisciplina`);

--
-- Índices para tabela `ficha_aluno`
--
ALTER TABLE `ficha_aluno`
  ADD PRIMARY KEY (`IdFichaAluno`),
  ADD KEY `fk_fichaaluno` (`IdUser`);

--
-- Índices para tabela `matriculas`
--
ALTER TABLE `matriculas`
  ADD PRIMARY KEY (`IdMatricula`),
  ADD KEY `fk_matricula_aluno` (`IdAluno`),
  ADD KEY `fk_matricula_func` (`IdFuncionario`),
  ADD KEY `fk_matricula_curso` (`IdCurso`);

--
-- Índices para tabela `notas`
--
ALTER TABLE `notas`
  ADD PRIMARY KEY (`IdNota`),
  ADD KEY `fk_nota_aluno` (`IdAluno`),
  ADD KEY `fk_nota_disciplina` (`IdDisciplina`);

--
-- Índices para tabela `perfis`
--
ALTER TABLE `perfis`
  ADD PRIMARY KEY (`IdPerfil`);

--
-- Índices para tabela `plano_estudos`
--
ALTER TABLE `plano_estudos`
  ADD PRIMARY KEY (`IdPlanoEstudo`),
  ADD KEY `fk_curso_disciplina` (`IdDisciplina`),
  ADD KEY `fk_curso_plano` (`IdCurso`);

--
-- Índices para tabela `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`IdUser`),
  ADD KEY `fk_perfil_user` (`Idperfil`);

--
-- AUTO_INCREMENT de tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `cursos`
--
ALTER TABLE `cursos`
  MODIFY `IdCurso` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `disciplina`
--
ALTER TABLE `disciplina`
  MODIFY `IdDisciplina` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `ficha_aluno`
--
ALTER TABLE `ficha_aluno`
  MODIFY `IdFichaAluno` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `matriculas`
--
ALTER TABLE `matriculas`
  MODIFY `IdMatricula` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `notas`
--
ALTER TABLE `notas`
  MODIFY `IdNota` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `perfis`
--
ALTER TABLE `perfis`
  MODIFY `IdPerfil` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `plano_estudos`
--
ALTER TABLE `plano_estudos`
  MODIFY `IdPlanoEstudo` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `users`
--
ALTER TABLE `users`
  MODIFY `IdUser` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restrições para despejos de tabelas
--

--
-- Limitadores para a tabela `ficha_aluno`
--
ALTER TABLE `ficha_aluno`
  ADD CONSTRAINT `fk_fichaaluno` FOREIGN KEY (`IdUser`) REFERENCES `users` (`IdUser`);

--
-- Limitadores para a tabela `matriculas`
--
ALTER TABLE `matriculas`
  ADD CONSTRAINT `fk_matricula_aluno` FOREIGN KEY (`IdAluno`) REFERENCES `users` (`IdUser`),
  ADD CONSTRAINT `fk_matricula_curso` FOREIGN KEY (`IdCurso`) REFERENCES `cursos` (`IdCurso`),
  ADD CONSTRAINT `fk_matricula_func` FOREIGN KEY (`IdFuncionario`) REFERENCES `users` (`IdUser`);

--
-- Limitadores para a tabela `notas`
--
ALTER TABLE `notas`
  ADD CONSTRAINT `fk_nota_aluno` FOREIGN KEY (`IdAluno`) REFERENCES `users` (`IdUser`),
  ADD CONSTRAINT `fk_nota_disciplina` FOREIGN KEY (`IdDisciplina`) REFERENCES `disciplina` (`IdDisciplina`);

--
-- Limitadores para a tabela `plano_estudos`
--
ALTER TABLE `plano_estudos`
  ADD CONSTRAINT `fk_curso_disciplina` FOREIGN KEY (`IdDisciplina`) REFERENCES `disciplina` (`IdDisciplina`),
  ADD CONSTRAINT `fk_curso_plano` FOREIGN KEY (`IdCurso`) REFERENCES `cursos` (`IdCurso`);

--
-- Limitadores para a tabela `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_perfil_user` FOREIGN KEY (`Idperfil`) REFERENCES `perfis` (`IdPerfil`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
