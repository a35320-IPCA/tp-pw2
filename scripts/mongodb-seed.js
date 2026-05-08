/* eslint-disable */
const { MongoClient } = require("mongodb");

require("dotenv").config();

/*
  Script de seed para MongoDB.
  Base: ipcapw
*/

const mongoUri = process.env.MONGODB_URI || "mongodb://a35320_db_user:yuZbp4foOao06eJ7@ac-7esch4f-shard-00-00.iiayayl.mongodb.net:27017,ac-7esch4f-shard-00-01.iiayayl.mongodb.net:27017,ac-7esch4f-shard-00-02.iiayayl.mongodb.net:27017/?ssl=true&replicaSet=atlas-j43qb3-shard-0&authSource=admin&appName=Cluster0";
const dbName = process.env.MONGODB_DB || "ipcapw";

let client;
let db;

async function main() {
  client = new MongoClient(mongoUri, {
    maxPoolSize: Number(process.env.MONGODB_MAX_POOL_SIZE || 30),
    minPoolSize: Number(process.env.MONGODB_MIN_POOL_SIZE || 5),
    maxIdleTimeMS: Number(process.env.MONGODB_MAX_IDLE_TIME_MS || 300000),
    connectTimeoutMS: Number(process.env.MONGODB_CONNECT_TIMEOUT_MS || 8000),
    socketTimeoutMS: Number(process.env.MONGODB_SOCKET_TIMEOUT_MS || 30000),
    serverSelectionTimeoutMS: Number(process.env.MONGODB_SERVER_SELECTION_TIMEOUT_MS || 5000)
  });

  await client.connect();
  const rawDb = client.db(dbName);
  db = new Proxy(rawDb, {
    get(target, property, receiver) {
      if (typeof property === "string" && !(property in target)) {
        return target.collection(property);
      }

      return Reflect.get(target, property, receiver);
    }
  });

  async function dropCollection(name) {
    try {
      await db.collection(name).drop();
    } catch (error) {
      if (error?.codeName !== "NamespaceNotFound" && error?.code !== 26) {
        throw error;
      }
    }
  }


// Limpeza para garantir reposicao completa no cluster
  await dropCollection("perfis");
  await dropCollection("cursos");
  await dropCollection("disciplinas");
  await dropCollection("planoEstudos");
  await dropCollection("users");
  await dropCollection("fichaAluno");
  await dropCollection("matriculas");
  await dropCollection("notas");

// ============================================
// COLECAO: perfis
// ============================================
  await db.perfis.insertMany([
  { _id: 1, perfil: "gestor" },
  { _id: 2, perfil: "aluno" },
  { _id: 3, perfil: "funcionario" }
]);

// ============================================
// COLECAO: cursos
// ============================================
  await db.cursos.insertMany([
  { _id: 3, curso: "Desenvolvimento Web Multimedia", sigla: "DWM" },
  { _id: 4, curso: "Design de Moda", sigla: "DM" },
  { _id: 5, curso: "Desporto e Atividades Nauticas", sigla: "DAN" },
  { _id: 6, curso: "Exportacao e Logistica", sigla: "EL" },
  { _id: 7, curso: "Gestao Financeira e Contabilistica", sigla: "GFC" },
  { _id: 8, curso: "Comercio Eletronico", sigla: "CE" },
  { _id: 10, curso: "OLA", sigla: "OLA" }
]);

// ============================================
// COLECAO: disciplinas
// ============================================
  await db.disciplinas.insertMany([
  { _id: 50, disciplina: "Programacao Web I", sigla: "PRG1" },
  { _id: 51, disciplina: "Analise de Dados", sigla: "AD" },
  { _id: 52, disciplina: "Sistemas Digitais", sigla: "SDIG" },
  { _id: 53, disciplina: "Matematica Aplicada", sigla: "MAT" },
  { _id: 54, disciplina: "Linguagens de Programacao I", sigla: "LP1" },
  { _id: 55, disciplina: "Programacao Web II", sigla: "PRG2" },
  { _id: 56, disciplina: "Bases de Dados", sigla: "BD" },
  { _id: 57, disciplina: "Design Multimedia", sigla: "DM" },
  { _id: 58, disciplina: "Linguagens de Programacao II", sigla: "LP2" },
  { _id: 59, disciplina: "Redes e Computadores", sigla: "RC" },
  { _id: 60, disciplina: "Desenho de Moda", sigla: "DMOD" },
  { _id: 61, disciplina: "Historia da Moda", sigla: "HMOD" },
  { _id: 62, disciplina: "Materiais Texteis", sigla: "MTEX" },
  { _id: 63, disciplina: "Tecnicas de Confecao I", sigla: "TC1" },
  { _id: 64, disciplina: "Modelagem", sigla: "MOD" },
  { _id: 65, disciplina: "Tecnicas de Confecao II", sigla: "TC2" },
  { _id: 66, disciplina: "Design de Colecoes", sigla: "DCOL" },
  { _id: 67, disciplina: "Software de Design", sigla: "SDES" },
  { _id: 68, disciplina: "Marketing de Moda", sigla: "MMOD" },
  { _id: 69, disciplina: "Fundamentos do Desporto", sigla: "FDES" },
  { _id: 70, disciplina: "Anatomia", sigla: "ANAT" },
  { _id: 71, disciplina: "Natacao", sigla: "NAT" },
  { _id: 72, disciplina: "Meteorologia", sigla: "MET" },
  { _id: 73, disciplina: "Atividades Nauticas I", sigla: "AN1" },
  { _id: 74, disciplina: "Fisiologia do Exercicio", sigla: "FEX" },
  { _id: 75, disciplina: "Seguranca e Salvamento", sigla: "SEG" },
  { _id: 76, disciplina: "Gestao Desportiva", sigla: "GDES" },
  { _id: 77, disciplina: "Comunicacao", sigla: "COM" },
  { _id: 78, disciplina: "Introducao a Gestao", sigla: "IGES" },
  { _id: 79, disciplina: "Logistica I", sigla: "LOG1" },
  { _id: 80, disciplina: "Economia", sigla: "ECO" },
  { _id: 81, disciplina: "Logistica II", sigla: "LOG2" },
  { _id: 82, disciplina: "Comercio Internacional", sigla: "CI" },
  { _id: 83, disciplina: "Gestao de Transportes", sigla: "GT" },
  { _id: 84, disciplina: "Sistemas de Informacao", sigla: "SI" },
  { _id: 85, disciplina: "Contabilidade", sigla: "CONT" },
  { _id: 86, disciplina: "Contabilidade I", sigla: "CONT1" },
  { _id: 87, disciplina: "Matematica Financeira", sigla: "MATF" },
  { _id: 88, disciplina: "Gestao", sigla: "GES" },
  { _id: 89, disciplina: "Contabilidade II", sigla: "CONT2" },
  { _id: 90, disciplina: "Fiscalidade", sigla: "FISC" },
  { _id: 91, disciplina: "Analise Financeira", sigla: "AFIN" },
  { _id: 92, disciplina: "Estatistica", sigla: "EST" },
  { _id: 93, disciplina: "Introducao ao Comercio Eletronico", sigla: "ICE" },
  { _id: 94, disciplina: "Marketing Digital", sigla: "MDIG" },
  { _id: 95, disciplina: "Plataformas de eCommerce", sigla: "PECOM" },
  { _id: 96, disciplina: "Gestao de Conteudos", sigla: "GC" },
  { _id: 97, disciplina: "Logistica", sigla: "LOG" }
]);

// ============================================
// COLECAO: planoEstudos
// ============================================
  await db.planoEstudos.insertMany([
  { _id: 197, idDisciplina: 50, idCurso: 3, semestre: 1 },
  { _id: 198, idDisciplina: 51, idCurso: 3, semestre: 2 },
  { _id: 199, idDisciplina: 52, idCurso: 3, semestre: 1 },
  { _id: 200, idDisciplina: 53, idCurso: 3, semestre: 1 },
  { _id: 201, idDisciplina: 54, idCurso: 3, semestre: 1 },
  { _id: 202, idDisciplina: 55, idCurso: 3, semestre: 2 },
  { _id: 203, idDisciplina: 56, idCurso: 3, semestre: 2 },
  { _id: 204, idDisciplina: 57, idCurso: 3, semestre: 2 },
  { _id: 205, idDisciplina: 58, idCurso: 3, semestre: 2 },
  { _id: 206, idDisciplina: 59, idCurso: 3, semestre: 2 },
  { _id: 207, idDisciplina: 60, idCurso: 4, semestre: 1 },
  { _id: 208, idDisciplina: 61, idCurso: 4, semestre: 1 },
  { _id: 209, idDisciplina: 62, idCurso: 4, semestre: 1 },
  { _id: 210, idDisciplina: 63, idCurso: 4, semestre: 1 },
  { _id: 211, idDisciplina: 64, idCurso: 4, semestre: 2 },
  { _id: 212, idDisciplina: 65, idCurso: 4, semestre: 2 },
  { _id: 213, idDisciplina: 66, idCurso: 4, semestre: 2 },
  { _id: 214, idDisciplina: 67, idCurso: 4, semestre: 2 },
  { _id: 215, idDisciplina: 68, idCurso: 4, semestre: 2 },
  { _id: 216, idDisciplina: 69, idCurso: 5, semestre: 1 },
  { _id: 217, idDisciplina: 70, idCurso: 5, semestre: 1 },
  { _id: 218, idDisciplina: 71, idCurso: 5, semestre: 1 },
  { _id: 219, idDisciplina: 72, idCurso: 5, semestre: 1 },
  { _id: 220, idDisciplina: 73, idCurso: 5, semestre: 2 },
  { _id: 221, idDisciplina: 74, idCurso: 5, semestre: 2 },
  { _id: 222, idDisciplina: 75, idCurso: 5, semestre: 2 },
  { _id: 223, idDisciplina: 76, idCurso: 5, semestre: 2 },
  { _id: 224, idDisciplina: 77, idCurso: 5, semestre: 2 },
  { _id: 225, idDisciplina: 78, idCurso: 6, semestre: 1 },
  { _id: 226, idDisciplina: 79, idCurso: 6, semestre: 1 },
  { _id: 227, idDisciplina: 80, idCurso: 6, semestre: 1 },
  { _id: 228, idDisciplina: 81, idCurso: 6, semestre: 2 },
  { _id: 229, idDisciplina: 82, idCurso: 6, semestre: 2 },
  { _id: 230, idDisciplina: 83, idCurso: 6, semestre: 2 },
  { _id: 231, idDisciplina: 84, idCurso: 6, semestre: 2 },
  { _id: 232, idDisciplina: 85, idCurso: 6, semestre: 2 },
  { _id: 233, idDisciplina: 86, idCurso: 7, semestre: 1 },
  { _id: 234, idDisciplina: 87, idCurso: 7, semestre: 1 },
  { _id: 235, idDisciplina: 88, idCurso: 7, semestre: 1 },
  { _id: 236, idDisciplina: 89, idCurso: 7, semestre: 2 },
  { _id: 237, idDisciplina: 90, idCurso: 7, semestre: 2 },
  { _id: 238, idDisciplina: 91, idCurso: 7, semestre: 2 },
  { _id: 239, idDisciplina: 92, idCurso: 7, semestre: 2 },
  { _id: 240, idDisciplina: 93, idCurso: 8, semestre: 1 },
  { _id: 241, idDisciplina: 94, idCurso: 8, semestre: 1 },
  { _id: 242, idDisciplina: 95, idCurso: 8, semestre: 2 },
  { _id: 243, idDisciplina: 96, idCurso: 8, semestre: 2 },
  { _id: 244, idDisciplina: 97, idCurso: 8, semestre: 2 },
  { _id: 245, idDisciplina: 51, idCurso: 8, semestre: 2 },
  { _id: 246, idDisciplina: 60, idCurso: 10, semestre: 1 }
]);

// ============================================
// COLECAO: users
// ============================================
  await db.users.insertMany([
  { _id: 4, login: "rui", pwd: "$2y$10$Pvze82pfBW.A69713I7d9O/3Txd4OEiyDUxZCTB.7Uk922pOlE/ay", idPerfil: 2 },
  { _id: 6, login: "bruno2", pwd: "$2y$10$zNnHRTeLgSKQ5HWpskOOM.S.2R2sQ2wwcqaLJVrbTidxXS3LHhGyO", idPerfil: 1 },
  { _id: 68, login: "funcionario", pwd: "$2y$10$NYZSX6IFS6Gu.qlB8cgkmeCNj7eMYZ76s6ySuqtfLui1dc2PlmHpm", idPerfil: 3 },
  { _id: 69, login: "utilizador1", pwd: "$2y$10$dGQwRQpRJdFeWF0UbfoM9eCzhUSTqquq4E6tkJmgwHzzpJZZXZj1i", idPerfil: 2 },
  { _id: 70, login: "utilizador2", pwd: "$2y$10$deoSX92pSdNAPeMRXINsRORTTsjUtePW1whxnqZTTaGquIx0F1.HS", idPerfil: 2 },
  { _id: 130, login: "Leonardo", pwd: "$2y$10$WQDVABagLG2OqNqB3YHpROUiZNCzR7aD/gEMrV5fTOzrLampLUxu2", idPerfil: 2 },
  { _id: 132, login: "Diogo", pwd: "$2y$10$34IJGc.IaHYEfMOKU5Bx3.OpNLd13id3GB2qFqiGMF987DIaINOWC", idPerfil: 2 }
]);

// ============================================
// COLECAO: fichaAluno
// ============================================
  await db.fichaAluno.insertMany([
  { _id: 3, nome: "utilizador1", idade: 19, telefone: 912345678, morada: "Rua do Comercio, Braga", nif: 901234567, dataNascimento: new Date("2007-02-15"), foto: null, idUser: 69, status: "Aprovada" },
  { _id: 4, nome: "utilizador2", idade: 18, telefone: 923456789, morada: "Rua da Liberdade, Braga", nif: 912345678, dataNascimento: new Date("2007-07-23"), foto: null, idUser: 70, status: "Aprovada" },
  { _id: 64, nome: "Diogo", idade: 18, telefone: 987654322, morada: "Cabecudos", nif: 987654322, dataNascimento: new Date("2000-12-06"), foto: null, idUser: 132, status: "Aprovada" }
]);

// ============================================
// COLECAO: matriculas
// ============================================
  await db.matriculas.insertMany([
  { _id: 2, idAluno: 69, nome: "utilizador1", idCurso: 3, foto: null, status: "Aceite", data: new Date("2026-03-18"), idFuncionario: 68 },
  { _id: 3, idAluno: 70, nome: "utilizador2", idCurso: 3, foto: null, status: "Aceite", data: new Date("2026-03-18"), idFuncionario: 68 },
  { _id: 63, idAluno: 132, nome: "Diogo", idCurso: 3, foto: null, status: "Aceite", data: new Date("2026-03-23"), idFuncionario: 68 }
]);

// ============================================
// COLECAO: notas
// ============================================
  await db.notas.insertMany([
  { _id: 1, idAluno: 69, idDisciplina: 50, nota: 15, anoLetivo: "2025/2026", epoca: "Normal", dataLancamento: new Date("2026-03-18T19:14:35") },
  { _id: 2, idAluno: 69, idDisciplina: 51, nota: 10, anoLetivo: "2025/2026", epoca: "Normal", dataLancamento: new Date("2026-03-18T19:14:35") },
  { _id: 11, idAluno: 70, idDisciplina: 50, nota: 16, anoLetivo: "2025/2026", epoca: "Normal", dataLancamento: new Date("2026-03-18T19:14:35") },
  { _id: 491, idAluno: 69, idDisciplina: 58, nota: 20, anoLetivo: "2024/2025", epoca: "Especial", dataLancamento: new Date("2026-03-23T10:10:13") },
  { _id: 492, idAluno: 132, idDisciplina: 57, nota: 20, anoLetivo: "2025/2026", epoca: "Normal", dataLancamento: new Date("2026-03-23T10:20:28") }
]);

// ============================================
// INDICES RECOMENDADOS
// ============================================
  await db.users.createIndex({ login: 1 }, { unique: true });
  await db.users.createIndex({ idPerfil: 1 });

  await db.fichaAluno.createIndex({ idUser: 1 });
  await db.fichaAluno.createIndex({ nif: 1 }, { unique: true });
  await db.fichaAluno.createIndex({ status: 1 });

  await db.matriculas.createIndex({ idAluno: 1 });
  await db.matriculas.createIndex({ idCurso: 1 });
  await db.matriculas.createIndex({ status: 1 });
  await db.matriculas.createIndex({ idFuncionario: 1 });

  await db.notas.createIndex({ idAluno: 1 });
  await db.notas.createIndex({ idDisciplina: 1 });
  await db.notas.createIndex({ anoLetivo: 1 });
  await db.notas.createIndex({ idAluno: 1, idDisciplina: 1, anoLetivo: 1 });

  await db.planoEstudos.createIndex({ idCurso: 1 });
  await db.planoEstudos.createIndex({ idDisciplina: 1 });
  await db.planoEstudos.createIndex({ idCurso: 1, semestre: 1 });

  await db.cursos.createIndex({ sigla: 1 }, { unique: true });
  await db.disciplinas.createIndex({ sigla: 1 }, { unique: true });

  console.log("Seed executado com sucesso.");
  try {
    await client.close();
  } catch {
    // Ignore shutdown errors after the data is written.
  }
  process.exit(0);
}

main().catch((error) => {
  console.error("Falha ao executar o seed:", error);

  process.exit(1);
});
