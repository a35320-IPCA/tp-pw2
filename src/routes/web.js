const express = require("express");
const bcrypt = require("bcryptjs");
const multer = require("multer");
const { getDb } = require("../config/db");

const router = express.Router();
const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 5 * 1024 * 1024 } });

const ALUNO_PAGES = ["aluno.php", "aluno_cursos.php", "ficha_aluno.php", "aluno_notas.php"];
const STAFF_PAGES = ["disciplinas.php", "cursos.php", "matriculas.php", "fichas.php", "planos.php", "planos_editar.php", "notas.php"];

function escapeRegex(text) {
  return String(text || "").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function ejsJson(data) {
  return JSON.stringify(data).replace(/</g, "\\u003c");
}

function roleInfo(req) {
  const perfil = String(req.session?.user?.perfil || "").toLowerCase();
  return {
    perfil,
    isAluno: perfil === "aluno",
    isFuncionario: perfil === "funcionario",
    isGestor: perfil === "gestor",
    isStaff: perfil === "funcionario" || perfil === "gestor",
    tipoUtilizador: perfil === "aluno" ? "Aluno" : perfil === "funcionario" ? "Funcionario" : "Gestor"
  };
}

function requireAuthPage(req, res) {
  if (!req.session?.authenticated || !req.session?.user) {
    res.redirect("/login");
    return false;
  }
  return true;
}

function redirectByRole(req, res) {
  const r = roleInfo(req);
  if (r.isAluno) {
    res.redirect("/aluno");
  } else if (r.isFuncionario) {
    res.redirect("/matriculas");
  } else {
    res.redirect("/disciplinas");
  }
}

function redirectWithMessage(res, path, type, message, extra = {}) {
  const params = new URLSearchParams({ type, message, ...extra });
  res.redirect(`/${path}?${params.toString()}`);
}

function parseRoleFromId(idPerfil) {
  if (idPerfil === 2) return "aluno";
  if (idPerfil === 3) return "funcionario";
  return "gestor";
}

function normalizeStatus(value) {
  const v = String(value || "").trim().toLowerCase();
  if (v === "aceite") return "Aceite";
  if (v === "rejeitada" || v === "rejeitado") return "Rejeitada";
  if (v === "pendente") return "Pendente";
  if (v === "submetida") return "Submetida";
  if (v === "aprovada") return "Aprovada";
  if (v === "rascunho") return "Rascunho";
  return "";
}

function normalizeEpoca(value) {
  const v = String(value || "").trim().toLowerCase();
  if (v === "normal") return "Normal";
  if (v === "recurso") return "Recurso";
  if (v === "especial") return "Especial";
  return "";
}

function formatDateTime(value) {
  if (!value) return "-";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "-";
  const pad = (n) => String(n).padStart(2, "0");
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function dataUrlFromBuffer(buffer) {
  if (!buffer) return "";
  const buf = Buffer.isBuffer(buffer) ? buffer : Buffer.from(buffer.buffer || buffer);
  if (!buf.length) return "";
  let mime = "application/octet-stream";
  if (buf.slice(0, 4).toString("hex") === "89504e47") mime = "image/png";
  if (buf.slice(0, 3).toString("hex") === "ffd8ff") mime = "image/jpeg";
  if (buf.slice(0, 4).toString() === "GIF8") mime = "image/gif";
  if (buf.slice(0, 4).toString() === "RIFF" && buf.slice(8, 12).toString() === "WEBP") mime = "image/webp";
  if (!mime.startsWith("image/")) return "";
  return `data:${mime};base64,${buf.toString("base64")}`;
}

async function nextNumericId(collection) {
  const row = await collection.find({ _id: { $type: "number" } }).sort({ _id: -1 }).limit(1).next();
  return Number(row?._id || 0) + 1;
}

function getUploadedFile(req, field) {
  const f = (req.files || []).find((file) => file.fieldname === field);
  return f || null;
}

router.use((req, res, next) => {
  if (req.query.action === "logout") {
    req.session.destroy(() => {
      res.redirect("/login");
    });
    return;
  }
  next();
});

router.get("/login", (req, res) => {
  if (req.session?.authenticated) {
    return redirectByRole(req, res);
  }
  return res.render("login", { error: "" });
});

router.post("/login", async (req, res) => {
  const login = String(req.body.login || "").trim();
  const pwd = String(req.body.pwd || "");
  if (!login || !pwd) {
    return res.render("login", { error: "Preencha login e palavra-passe." });
  }

  const db = getDb();
  const user = await db.collection("users").findOne(
    { login: { $regex: `^${escapeRegex(login)}$`, $options: "i" } },
    { projection: { _id: 1, login: 1, pwd: 1, idPerfil: 1 } }
  );

  if (!user) {
    return res.render("login", { error: "Login ou palavra-passe invalidos." });
  }

  const normalizedHash = String(user.pwd || "").replace(/^\$2y\$/, "$2b$");
  const isValid = await bcrypt.compare(pwd, normalizedHash);
  if (!isValid) {
    return res.render("login", { error: "Login ou palavra-passe invalidos." });
  }

  const perfilDoc = await db.collection("perfis").findOne({ _id: Number(user.idPerfil) });
  const perfil = String(perfilDoc?.perfil || parseRoleFromId(Number(user.idPerfil))).toLowerCase();

  req.session.authenticated = true;
  req.session.user = {
    idUser: Number(user._id),
    login: user.login,
    idPerfil: Number(user.idPerfil),
    perfil
  };

  return redirectByRole(req, res);
});

router.get("/criar_conta", async (_req, res) => {
  const perfis = await getDb().collection("perfis").find({ perfil: { $not: /gestor/i } }).sort({ perfil: 1 }).toArray();
  return res.render("criar_conta", { error: "", success: "", perfis, form: {} });
});

router.post("/criar_conta", async (req, res) => {
  const login = String(req.body.login || "").trim();
  const pwd = String(req.body.pwd || "");
  const idPerfil = Number(req.body.Idperfil || 0);
  const db = getDb();
  const perfis = await db.collection("perfis").find({ perfil: { $not: /gestor/i } }).sort({ perfil: 1 }).toArray();

  if (!login || !pwd || idPerfil <= 0) {
    return res.render("criar_conta", { error: "Preencha login, password e perfil.", success: "", perfis, form: req.body });
  }

  const exists = await db.collection("users").findOne({ login: { $regex: `^${escapeRegex(login)}$`, $options: "i" } }, { projection: { _id: 1 } });
  if (exists) {
    return res.render("criar_conta", { error: "Esse nome de utilizador ja existe.", success: "", perfis, form: req.body });
  }

  const newId = await nextNumericId(db.collection("users"));
  const hash = await bcrypt.hash(pwd, 10);
  await db.collection("users").insertOne({ _id: newId, login, pwd: hash, idPerfil });

  const perfilDoc = await db.collection("perfis").findOne({ _id: idPerfil });
  const perfil = String(perfilDoc?.perfil || "").toLowerCase();
  if (perfil === "aluno") {
    req.session.authenticated = true;
    req.session.user = { idUser: newId, login, idPerfil, perfil: "aluno" };
    return res.redirect("/ficha_aluno?from=signup");
  }

  return res.render("criar_conta", { error: "", success: "Conta criada com sucesso. Ja podes iniciar sessao.", perfis, form: {} });
});

router.get("/aluno", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  const r = roleInfo(req);
  if (!r.isAluno) {
    return redirectByRole(req, res);
  }

  if (req.query.action === "foto_ficha") {
    const fichaFoto = await getDb().collection("fichaAluno").findOne({ idUser: Number(req.session.user.idUser) });
    if (!fichaFoto?.foto) {
      return res.status(404).end();
    }
    res.setHeader("Content-Type", "image/jpeg");
    return res.send(fichaFoto.foto);
  }

  const db = getDb();
  const idAluno = Number(req.session.user.idUser);
  const ficha = await db.collection("fichaAluno").findOne({ idUser: idAluno });
  const matriculaAceite = await db.collection("matriculas").aggregate([
    { $match: { idAluno, status: { $regex: /^aceite$/i } } },
    { $lookup: { from: "cursos", localField: "idCurso", foreignField: "_id", as: "curso" } },
    { $unwind: { path: "$curso", preserveNullAndEmptyArrays: true } },
    { $limit: 1 }
  ]).next();
  const pedidosPendentes = await db.collection("matriculas").aggregate([
    { $match: { idAluno, status: { $regex: /^pendente$/i } } },
    { $lookup: { from: "cursos", localField: "idCurso", foreignField: "_id", as: "curso" } },
    { $unwind: { path: "$curso", preserveNullAndEmptyArrays: true } },
    { $project: { status: 1, curso: "$curso.curso" } }
  ]).toArray();
  const cursos = await db.collection("cursos").find({}).sort({ curso: 1 }).toArray();

  const statusFicha = String(ficha?.status || "").toLowerCase();
  const podeMatricular = !!ficha && statusFicha === "aprovada";

  return res.render("aluno", {
    tipoUtilizador: "Aluno",
    message: String(req.query.message || ""),
    type: String(req.query.type || ""),
    ficha,
    statusFicha,
    podeMatricular,
    matriculaAceite,
    pedidosPendentes,
    cursos,
    fichaJson: ejsJson({
      modo: "aluno",
      nome: ficha?.nome || "",
      idade: Number(ficha?.idade || 0),
      telefone: String(ficha?.telefone || ""),
      morada: String(ficha?.morada || ""),
      nif: String(ficha?.nif || ""),
      data_nascimento: ficha?.dataNascimento ? new Date(ficha.dataNascimento).toISOString().slice(0, 10) : "",
      status: String(ficha?.status || ""),
      id_user: idAluno,
      foto_data_url: dataUrlFromBuffer(ficha?.foto || null),
      foto_url: "/aluno?action=foto_ficha"
    })
  });
});

router.post("/aluno", upload.any(), async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  const r = roleInfo(req);
  if (!r.isAluno) {
    return redirectByRole(req, res);
  }

  const action = String(req.body.action || "");
  const db = getDb();
  const idAluno = Number(req.session.user.idUser);
  const ficha = await db.collection("fichaAluno").findOne({ idUser: idAluno });
  const statusFicha = String(ficha?.status || "").toLowerCase();

  if (action === "submit_ficha") {
    if (!ficha) {
      return redirectWithMessage(res, "aluno.php", "error", "Primeiro tens de criar a ficha de aluno.");
    }
    if (!["rascunho", "rejeitada"].includes(statusFicha)) {
      return redirectWithMessage(res, "aluno.php", "error", "A ficha so pode ser submetida quando estiver em Rascunho ou Rejeitada.");
    }
    await db.collection("fichaAluno").updateOne({ idUser: idAluno }, { $set: { status: "Submetida" } });
    return redirectWithMessage(res, "aluno.php", "success", "Ficha submetida com sucesso.");
  }

  if (action !== "request_enrollment") {
    return redirectWithMessage(res, "aluno.php", "error", "Acao invalida.");
  }

  if (statusFicha !== "aprovada") {
    return redirectWithMessage(res, "aluno.php", "error", "So podes enviar matricula quando a ficha estiver Aprovada.");
  }

  const idCurso = Number(req.body.IdCurso || 0);
  if (idCurso <= 0) {
    return redirectWithMessage(res, "aluno.php", "error", "Seleciona um curso valido.");
  }

  const curso = await db.collection("cursos").findOne({ _id: idCurso }, { projection: { _id: 1 } });
  if (!curso) {
    return redirectWithMessage(res, "aluno.php", "error", "O curso selecionado nao existe.");
  }

  const file = getUploadedFile(req, "Foto");
  const foto = file?.buffer || ficha?.foto || null;
  if (!foto) {
    return redirectWithMessage(res, "aluno.php", "error", "E obrigatorio enviar comprovativo com foto.");
  }

  const existing = await db.collection("matriculas").findOne({ idAluno });
  if (existing && String(existing.status || "").toLowerCase() === "aceite") {
    return redirectWithMessage(res, "aluno.php", "error", "Ja tens uma matricula aceite.");
  }
  if (existing && String(existing.status || "").toLowerCase() === "pendente") {
    return redirectWithMessage(res, "aluno.php", "error", "Ja tens um pedido de matricula pendente.");
  }

  const doc = {
    idAluno,
    nome: String(ficha?.nome || req.session.user.login),
    idCurso,
    foto,
    status: "Pendente",
    data: new Date(),
    idFuncionario: null
  };

  if (existing) {
    await db.collection("matriculas").updateOne({ _id: existing._id }, { $set: doc });
  } else {
    const newId = await nextNumericId(db.collection("matriculas"));
    await db.collection("matriculas").insertOne({ _id: newId, ...doc });
  }

  return redirectWithMessage(res, "aluno.php", "success", "Pedido de matricula enviado com sucesso.");
});

router.get("/ficha_aluno", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  const r = roleInfo(req);
  if (!r.isAluno) return redirectByRole(req, res);

  if (req.query.action === "foto") {
    const ficha = await getDb().collection("fichaAluno").findOne({ idUser: Number(req.session.user.idUser) });
    if (!ficha?.foto) {
      return res.status(404).end();
    }
    res.setHeader("Content-Type", "image/jpeg");
    return res.send(ficha.foto);
  }

  const ficha = await getDb().collection("fichaAluno").findOne({ idUser: Number(req.session.user.idUser) });
  return res.render("ficha_aluno", {
    ficha,
    message: String(req.query.message || ""),
    type: String(req.query.type || ""),
    statusAtual: String(ficha?.status || "Sem ficha")
  });
});

router.post("/ficha_aluno", upload.any(), async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isAluno) return redirectByRole(req, res);

  const idUser = Number(req.session.user.idUser);
  const db = getDb();
  const existing = await db.collection("fichaAluno").findOne({ idUser });

  const nome = String(req.body.nome || "").trim();
  const idade = Number(req.body.idade || 0);
  const telefone = String(req.body.telefone || "").trim();
  const morada = String(req.body.morada || "").trim();
  const nif = String(req.body.nif || "").trim();
  const dataNascimento = String(req.body.data_nascimento || "").trim();
  if (!nome || idade <= 0 || !telefone || !morada || !nif || !dataNascimento) {
    return redirectWithMessage(res, "ficha_aluno.php", "error", "Preenche todos os campos obrigatorios.");
  }

  const file = getUploadedFile(req, "foto");
  if (!existing && !file) {
    return redirectWithMessage(res, "ficha_aluno.php", "error", "A foto e obrigatoria no primeiro envio da ficha.");
  }

  const payload = {
    nome,
    idade,
    telefone,
    morada,
    nif,
    dataNascimento: new Date(dataNascimento),
    status: "Rascunho"
  };
  if (file?.buffer) payload.foto = file.buffer;

  if (existing) {
    await db.collection("fichaAluno").updateOne({ idUser }, { $set: payload });
  } else {
    const _id = await nextNumericId(db.collection("fichaAluno"));
    await db.collection("fichaAluno").insertOne({ _id, idUser, ...payload });
  }

  return redirectWithMessage(res, "aluno.php", "success", "Ficha guardada com sucesso. Estado definido como Rascunho.");
});

router.get("/aluno_cursos", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  const r = roleInfo(req);
  if (!r.isAluno) return redirectByRole(req, res);

  const cursos = await getDb().collection("planoEstudos").aggregate([
    { $lookup: { from: "cursos", localField: "idCurso", foreignField: "_id", as: "curso" } },
    { $unwind: "$curso" },
    { $lookup: { from: "disciplinas", localField: "idDisciplina", foreignField: "_id", as: "disciplina" } },
    { $unwind: "$disciplina" },
    { $project: { curso: "$curso.curso", disciplina: "$disciplina.disciplina", semestre: 1 } },
    { $sort: { curso: 1, semestre: 1, disciplina: 1 } }
  ]).toArray();

  const cursosComPlano = {};
  for (const row of cursos) {
    if (!cursosComPlano[row.curso]) cursosComPlano[row.curso] = [];
    cursosComPlano[row.curso].push({ disciplina: row.disciplina, semestre: Number(row.semestre || 1) });
  }

  return res.render("aluno_cursos", { cursosComPlano });
});

router.get("/aluno_notas", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isAluno) return redirectByRole(req, res);

  const db = getDb();
  const idUser = Number(req.session.user.idUser);
  const user = await db.collection("users").findOne({ _id: idUser });
  const ficha = await db.collection("fichaAluno").findOne({ idUser });

  const matriculaAceite = await db.collection("matriculas").aggregate([
    { $match: { idAluno: idUser, status: { $regex: /^aceite$/i } } },
    { $lookup: { from: "cursos", localField: "idCurso", foreignField: "_id", as: "curso" } },
    { $unwind: "$curso" },
    { $limit: 1 }
  ]).next();

  let notasAluno = [];
  if (matriculaAceite) {
    notasAluno = await db.collection("notas").aggregate([
      { $match: { idAluno: idUser } },
      { $lookup: { from: "disciplinas", localField: "idDisciplina", foreignField: "_id", as: "disciplina" } },
      { $unwind: { path: "$disciplina", preserveNullAndEmptyArrays: true } },
      { $sort: { dataLancamento: -1 } }
    ]).toArray();
  }

  const payloadNotasJs = {
    aluno: String(ficha?.nome || user?.login || ""),
    curso: String(matriculaAceite?.curso?.curso || ""),
    notas: notasAluno.map((n) => {
      const nota = Number(n.nota || 0);
      return {
        disciplina: String(n.disciplina?.disciplina || "-"),
        nota,
        anoLetivo: String(n.anoLetivo || "-"),
        epoca: String(n.epoca || "-"),
        dataLancamento: formatDateTime(n.dataLancamento),
        situacao: nota >= 10 ? "Aprovado" : "Reprovado"
      };
    })
  };

  return res.render("aluno_notas", {
    matriculaAceite,
    alunoNome: String(ficha?.nome || user?.login || ""),
    notasAluno,
    payloadNotasJs: ejsJson(payloadNotasJs),
    formatDateTime,
    message: String(req.query.message || ""),
    type: String(req.query.type || "")
  });
});

router.get("/disciplinas", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  const r = roleInfo(req);
  if (r.isAluno) return res.redirect("/aluno");
  if (r.isFuncionario) return res.redirect("/matriculas");

  const action = String(req.query.action || "list");
  const db = getDb();
  const disciplinas = await db.collection("disciplinas").find({}).sort({ _id: -1 }).toArray();
  const editData = action === "edit" ? await db.collection("disciplinas").findOne({ _id: Number(req.query.id || 0) }) : null;
  return res.render("disciplinas", {
    action,
    disciplinas,
    editData,
    message: String(req.query.message || ""),
    type: String(req.query.type || "")
  });
});

router.post("/disciplinas", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isGestor) return redirectWithMessage(res, "disciplinas.php", "error", "Sem permissoes para gerir unidades curriculares.");

  const db = getDb();
  const action = String(req.body.action || "");
  if (action === "create") {
    const _id = await nextNumericId(db.collection("disciplinas"));
    await db.collection("disciplinas").insertOne({ _id, disciplina: String(req.body.Disciplina || "").trim(), sigla: String(req.body.Sigla || "").trim() });
    return redirectWithMessage(res, "disciplinas.php", "success", "Disciplina criada com sucesso.");
  }
  if (action === "update") {
    await db.collection("disciplinas").updateOne({ _id: Number(req.body.IdDisciplina || 0) }, { $set: { disciplina: String(req.body.Disciplina || "").trim(), sigla: String(req.body.Sigla || "").trim() } });
    return redirectWithMessage(res, "disciplinas.php", "success", "Disciplina atualizada com sucesso.");
  }
  if (action === "delete") {
    await db.collection("disciplinas").deleteOne({ _id: Number(req.body.IdDisciplina || 0) });
    return redirectWithMessage(res, "disciplinas.php", "success", "Disciplina removida com sucesso.");
  }
  return redirectWithMessage(res, "disciplinas.php", "error", "Acao invalida.");
});

router.get("/cursos", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  const r = roleInfo(req);
  if (r.isAluno) return res.redirect("/aluno");
  if (r.isFuncionario) return res.redirect("/matriculas");

  const action = String(req.query.action || "list");
  const db = getDb();
  const cursos = await db.collection("cursos").find({}).sort({ _id: -1 }).toArray();
  const editData = action === "edit" ? await db.collection("cursos").findOne({ _id: Number(req.query.id || 0) }) : null;

  const plano = await db.collection("planoEstudos").aggregate([
    { $lookup: { from: "disciplinas", localField: "idDisciplina", foreignField: "_id", as: "disciplina" } },
    { $unwind: { path: "$disciplina", preserveNullAndEmptyArrays: true } },
    { $project: { idCurso: 1, disciplina: "$disciplina.disciplina", semestre: 1 } }
  ]).toArray();
  const disciplinasPorCurso = {};
  for (const row of plano) {
    if (!disciplinasPorCurso[row.idCurso]) disciplinasPorCurso[row.idCurso] = [];
    disciplinasPorCurso[row.idCurso].push({ Disciplina: row.disciplina, semestre: row.semestre });
  }

  return res.render("cursos", {
    action,
    cursos,
    editData,
    disciplinasPorCurso: ejsJson(disciplinasPorCurso),
    message: String(req.query.message || ""),
    type: String(req.query.type || "")
  });
});

router.post("/cursos", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isGestor) return redirectWithMessage(res, "cursos.php", "error", "Sem permissoes para gerir cursos.");

  const db = getDb();
  const action = String(req.body.action || "");
  if (action === "create") {
    const _id = await nextNumericId(db.collection("cursos"));
    await db.collection("cursos").insertOne({ _id, curso: String(req.body.Curso || "").trim(), sigla: String(req.body.Sigla || "").trim() });
    return redirectWithMessage(res, "cursos.php", "success", "Curso criado com sucesso.");
  }
  if (action === "update") {
    await db.collection("cursos").updateOne({ _id: Number(req.body.IdCurso || 0) }, { $set: { curso: String(req.body.Curso || "").trim(), sigla: String(req.body.Sigla || "").trim() } });
    return redirectWithMessage(res, "cursos.php", "success", "Curso atualizado com sucesso.");
  }
  if (action === "delete") {
    await db.collection("cursos").deleteOne({ _id: Number(req.body.IdCurso || 0) });
    return redirectWithMessage(res, "cursos.php", "success", "Curso removido com sucesso.");
  }
  return redirectWithMessage(res, "cursos.php", "error", "Acao invalida.");
});

router.get("/planos", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isGestor) return res.redirect("/matriculas");
  const db = getDb();
  const cursos = await db.collection("cursos").find({}).sort({ curso: 1 }).toArray();
  const disciplinas = await db.collection("disciplinas").find({}).sort({ disciplina: 1 }).toArray();
  return res.render("planos", { cursos, disciplinas, message: String(req.query.message || ""), type: String(req.query.type || "") });
});

router.post("/planos", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isGestor) return redirectWithMessage(res, "planos.php", "error", "Sem permissoes para criar planos de estudo.");
  if (String(req.body.action || "") !== "create") return redirectWithMessage(res, "planos.php", "error", "Acao invalida.");

  const db = getDb();
  const _id = await nextNumericId(db.collection("planoEstudos"));
  await db.collection("planoEstudos").insertOne({ _id, idCurso: Number(req.body.IdCurso || 0), idDisciplina: Number(req.body.IdDisciplina || 0), semestre: Number(req.body.Semestre || 1) });
  return redirectWithMessage(res, "planos.php", "success", "Plano de estudo criado com sucesso.");
});

router.get("/planos_editar", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isGestor) return res.redirect("/matriculas");

  const db = getDb();
  const idCurso = Number(req.query.id_curso || 0);
  const cursos = await db.collection("cursos").find({}).sort({ curso: 1 }).toArray();
  const disciplinas = await db.collection("disciplinas").find({}).sort({ disciplina: 1 }).toArray();
  const rows = idCurso > 0
    ? await db.collection("planoEstudos").aggregate([
      { $match: { idCurso } },
      { $lookup: { from: "disciplinas", localField: "idDisciplina", foreignField: "_id", as: "disciplina" } },
      { $unwind: { path: "$disciplina", preserveNullAndEmptyArrays: true } },
      { $sort: { semestre: 1, "disciplina.disciplina": 1 } }
    ]).toArray()
    : [];

  const editData = req.query.view_action === "edit"
    ? await db.collection("planoEstudos").findOne({ idCurso: Number(req.query.id_curso || 0), idDisciplina: Number(req.query.id_disciplina || 0), semestre: Number(req.query.semestre || 0) })
    : null;

  return res.render("planos_editar", {
    cursos,
    disciplinas,
    rows,
    idCursoSelecionado: idCurso,
    editData,
    message: String(req.query.message || ""),
    type: String(req.query.type || "")
  });
});

router.post("/planos_editar", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isGestor) return redirectWithMessage(res, "planos_editar.php", "error", "Sem permissoes para gerir planos de estudo.");

  const db = getDb();
  const action = String(req.body.action || "");
  if (action === "create") {
    const _id = await nextNumericId(db.collection("planoEstudos"));
    await db.collection("planoEstudos").insertOne({ _id, idCurso: Number(req.body.IdCurso || 0), idDisciplina: Number(req.body.IdDisciplina || 0), semestre: Number(req.body.Semestre || 1) });
    return redirectWithMessage(res, "planos_editar.php", "success", "Ligacao criada com sucesso.", { id_curso: String(req.body.IdCurso || "") });
  }
  if (action === "update") {
    await db.collection("planoEstudos").updateOne(
      { idCurso: Number(req.body.old_IdCurso || 0), idDisciplina: Number(req.body.old_IdDisciplina || 0), semestre: Number(req.body.old_Semestre || 0) },
      { $set: { idCurso: Number(req.body.IdCurso || 0), idDisciplina: Number(req.body.IdDisciplina || 0), semestre: Number(req.body.Semestre || 1) } }
    );
    return redirectWithMessage(res, "planos_editar.php", "success", "Ligacao atualizada com sucesso.", { id_curso: String(req.body.IdCurso || "") });
  }
  if (action === "delete") {
    await db.collection("planoEstudos").deleteOne({ idCurso: Number(req.body.IdCurso || 0), idDisciplina: Number(req.body.IdDisciplina || 0), semestre: Number(req.body.Semestre || 0) });
    return redirectWithMessage(res, "planos_editar.php", "success", "Ligacao removida com sucesso.", { id_curso: String(req.body.IdCurso || "") });
  }
  return redirectWithMessage(res, "planos_editar.php", "error", "Acao invalida.");
});

router.get("/fichas", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  const r = roleInfo(req);
  if (r.isAluno) return res.redirect("/aluno");

  if (req.query.action === "foto") {
    const row = await getDb().collection("fichaAluno").findOne({ idUser: Number(req.query.id_user || 0) });
    if (!row?.foto) return res.status(404).end();
    res.setHeader("Content-Type", "image/jpeg");
    return res.send(row.foto);
  }

  const fichas = await getDb().collection("fichaAluno").find({}).sort({ nome: 1 }).toArray();
  return res.render("fichas", {
    isGestor: r.isGestor,
    tipoUtilizador: r.tipoUtilizador,
    fichas,
    message: String(req.query.message || ""),
    type: String(req.query.type || ""),
    fichasJson: ejsJson({
      fichas: fichas.map((f) => ({
        id_user: Number(f.idUser),
        nome: f.nome,
        idade: Number(f.idade || 0),
        telefone: String(f.telefone || ""),
        morada: String(f.morada || ""),
        nif: String(f.nif || ""),
        data_nascimento: f.dataNascimento ? new Date(f.dataNascimento).toISOString().slice(0, 10) : "",
        status: String(f.status || ""),
        foto_data_url: dataUrlFromBuffer(f.foto || null),
        foto_url: `/fichas?action=foto&id_user=${Number(f.idUser)}`
      }))
    })
  });
});

router.post("/fichas", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isGestor) return redirectWithMessage(res, "fichas.php", "error", "Sem permissoes para validar fichas de aluno.");
  if (String(req.body.action || "") !== "validate_ficha") return redirectWithMessage(res, "fichas.php", "error", "Acao invalida.");
  const idUser = Number(req.body.IdUserFicha || 0);
  const status = normalizeStatus(req.body.StatusFicha || "");
  if (!idUser || !["Aprovada", "Rejeitada"].includes(status)) return redirectWithMessage(res, "fichas.php", "error", "Estado invalido para ficha de aluno.");
  await getDb().collection("fichaAluno").updateOne({ idUser, status: "Submetida" }, { $set: { status } });
  return redirectWithMessage(res, "fichas.php", "success", "Ficha de aluno validada com sucesso.");
});

router.get("/matriculas", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  const r = roleInfo(req);
  if (r.isAluno) return res.redirect("/aluno");

  const db = getDb();
  if (req.query.action === "foto") {
    const row = await db.collection("matriculas").findOne({ idAluno: Number(req.query.IdAluno || 0) });
    if (!row?.foto) return res.status(404).end();
    res.setHeader("Content-Type", "application/octet-stream");
    return res.send(row.foto);
  }

  const action = String(req.query.action || "list");
  const rows = await db.collection("matriculas").aggregate([
    { $lookup: { from: "cursos", localField: "idCurso", foreignField: "_id", as: "curso" } },
    { $unwind: { path: "$curso", preserveNullAndEmptyArrays: true } },
    { $sort: { data: -1 } }
  ]).toArray();
  const cursos = await db.collection("cursos").find({}).sort({ curso: 1 }).toArray();
  const alunos = await db.collection("users").aggregate([
    { $match: { idPerfil: 2 } },
    { $lookup: { from: "fichaAluno", localField: "_id", foreignField: "idUser", as: "ficha" } },
    { $unwind: { path: "$ficha", preserveNullAndEmptyArrays: true } },
    { $project: { _id: 1, login: 1, nome: "$ficha.nome" } },
    { $sort: { login: 1 } }
  ]).toArray();
  const editData = action === "edit" ? await db.collection("matriculas").findOne({ idAluno: Number(req.query.IdAluno || 0) }) : null;

  return res.render("matriculas", {
    isGestor: r.isGestor,
    isStaff: r.isStaff,
    tipoUtilizador: r.tipoUtilizador,
    action,
    rows,
    cursos,
    alunos,
    editData,
    message: String(req.query.message || ""),
    type: String(req.query.type || "")
  });
});

router.post("/matriculas", upload.any(), async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  const r = roleInfo(req);
  if (!r.isStaff) return redirectWithMessage(res, "matriculas.php", "error", "Sem permissoes para alterar matriculas.");

  const db = getDb();
  const action = String(req.body.action || "");
  if (action === "validate_request") {
    const idAluno = Number(req.body.IdAluno || 0);
    const status = normalizeStatus(req.body.Status || "");
    if (!idAluno || !["Aceite", "Rejeitada"].includes(status)) return redirectWithMessage(res, "matriculas.php", "error", "Estado invalido para validacao de pedido.");
    await db.collection("matriculas").updateOne({ idAluno, status: { $regex: /^pendente$/i } }, { $set: { status, idFuncionario: Number(req.session.user.idUser) } });
    return redirectWithMessage(res, "matriculas.php", "success", "Pedido validado com sucesso.");
  }

  if (!r.isGestor) return redirectWithMessage(res, "matriculas.php", "error", "Sem permissoes para alterar matriculas.");

  const idAlunoSel = Number(req.body.IdAlunoSelecionado || req.body.IdAluno || 0);
  const idCurso = Number(req.body.IdCurso || 0);
  const status = normalizeStatus(req.body.Status || "Aceite") || "Aceite";
  const aluno = await db.collection("users").findOne({ _id: idAlunoSel });
  const foto = getUploadedFile(req, "Foto")?.buffer;

  if (action === "create") {
    const _id = await nextNumericId(db.collection("matriculas"));
    await db.collection("matriculas").insertOne({ _id, idAluno: idAlunoSel, nome: String(aluno?.login || ""), idCurso, foto: foto || null, status, data: new Date(), idFuncionario: null });
    return redirectWithMessage(res, "matriculas.php", "success", "Matricula criada com sucesso.");
  }
  if (action === "update") {
    const idAnterior = Number(req.body.IdAluno || 0);
    const update = { idAluno: idAlunoSel, nome: String(aluno?.login || ""), idCurso, status };
    if (foto) update.foto = foto;
    if (["Aceite", "Rejeitada"].includes(status)) update.idFuncionario = Number(req.session.user.idUser);
    await db.collection("matriculas").updateOne({ idAluno: idAnterior }, { $set: update });
    return redirectWithMessage(res, "matriculas.php", "success", "Matricula atualizada com sucesso.");
  }
  if (action === "delete") {
    await db.collection("matriculas").deleteOne({ idAluno: Number(req.body.IdAluno || 0) });
    return redirectWithMessage(res, "matriculas.php", "success", "Matricula removida com sucesso.");
  }
  return redirectWithMessage(res, "matriculas.php", "error", "Acao invalida.");
});

router.get("/notas", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isStaff) return res.redirect("/aluno");

  const db = getDb();
  const action = String(req.query.action || "list");
  const filtros = {
    idCurso: Number(req.query.id_curso || 0),
    idAluno: Number(req.query.id_aluno || 0),
    idDisciplina: Number(req.query.id_disciplina || 0),
    anoLetivo: String(req.query.ano_letivo || ""),
    epoca: String(req.query.epoca || "")
  };

  const cursos = await db.collection("cursos").find({}).sort({ curso: 1 }).toArray();
  const alunos = await db.collection("matriculas").aggregate([
    { $match: { status: { $regex: /^aceite$/i } } },
    { $lookup: { from: "users", localField: "idAluno", foreignField: "_id", as: "user" } },
    { $unwind: { path: "$user", preserveNullAndEmptyArrays: true } },
    { $project: { _id: "$idAluno", login: "$user.login", idCurso: 1 } }
  ]).toArray();

  const disciplinas = await db.collection("disciplinas").find({}).sort({ disciplina: 1 }).toArray();
  const editData = action === "edit" ? await db.collection("notas").findOne({ _id: Number(req.query.id_nota || 0) }) : null;

  const match = {};
  if (filtros.idAluno > 0) match.idAluno = filtros.idAluno;
  if (filtros.idDisciplina > 0) match.idDisciplina = filtros.idDisciplina;
  if (filtros.anoLetivo) match.anoLetivo = filtros.anoLetivo;
  if (filtros.epoca) match.epoca = filtros.epoca;

  const notas = await db.collection("notas").aggregate([
    { $match: match },
    { $lookup: { from: "users", localField: "idAluno", foreignField: "_id", as: "aluno" } },
    { $unwind: { path: "$aluno", preserveNullAndEmptyArrays: true } },
    { $lookup: { from: "disciplinas", localField: "idDisciplina", foreignField: "_id", as: "disciplina" } },
    { $unwind: { path: "$disciplina", preserveNullAndEmptyArrays: true } },
    { $lookup: { from: "matriculas", localField: "idAluno", foreignField: "idAluno", as: "matricula" } },
    { $unwind: { path: "$matricula", preserveNullAndEmptyArrays: true } },
    { $lookup: { from: "cursos", localField: "matricula.idCurso", foreignField: "_id", as: "curso" } },
    { $unwind: { path: "$curso", preserveNullAndEmptyArrays: true } },
    { $sort: { dataLancamento: -1 } }
  ]).toArray();

  const pautaRows = notas.map((n) => ({
    id_nota: Number(n._id),
    id_aluno: Number(n.idAluno),
    aluno: String(n.aluno?.login || ""),
    curso: String(n.curso?.curso || ""),
    disciplina: String(n.disciplina?.disciplina || ""),
    nota: Number(n.nota || 0),
    ano_letivo: String(n.anoLetivo || ""),
    epoca: String(n.epoca || ""),
    data_lancamento: formatDateTime(n.dataLancamento),
    situacao: Number(n.nota || 0) >= 10 ? "Aprovado" : "Reprovado"
  }));

  return res.render("notas", {
    action,
    cursos,
    alunos,
    disciplinas,
    notas,
    filtros,
    editData,
    pautaJson: ejsJson({
      curso_id: filtros.idCurso || "",
      aluno_id: filtros.idAluno || "",
      disciplina_id: filtros.idDisciplina || "",
      ano_letivo: filtros.anoLetivo,
      epoca: filtros.epoca,
      rows: pautaRows
    }),
    formatDateTime,
    message: String(req.query.message || ""),
    type: String(req.query.type || "")
  });
});

router.post("/notas", async (req, res) => {
  if (!requireAuthPage(req, res)) return;
  if (!roleInfo(req).isStaff) return redirectWithMessage(res, "notas.php", "error", "Sem permissoes para gerir notas.");

  const db = getDb();
  const action = String(req.body.action || "");

  if (action === "create") {
    const _id = await nextNumericId(db.collection("notas"));
    await db.collection("notas").insertOne({
      _id,
      idAluno: Number(req.body.IdAluno || 0),
      idDisciplina: Number(req.body.IdDisciplina || 0),
      nota: Number(req.body.Nota || 0),
      anoLetivo: String(req.body.AnoLetivo || ""),
      epoca: normalizeEpoca(req.body.Epoca || "") || "Normal",
      dataLancamento: new Date()
    });
    return redirectWithMessage(res, "notas.php", "success", "Nota registada com sucesso.");
  }

  if (action === "update") {
    await db.collection("notas").updateOne({ _id: Number(req.body.IdNota || 0) }, {
      $set: {
        idAluno: Number(req.body.IdAluno || 0),
        idDisciplina: Number(req.body.IdDisciplina || 0),
        nota: Number(req.body.Nota || 0),
        anoLetivo: String(req.body.AnoLetivo || ""),
        epoca: normalizeEpoca(req.body.Epoca || "") || "Normal",
        dataLancamento: new Date()
      }
    });
    return redirectWithMessage(res, "notas.php", "success", "Nota atualizada com sucesso.");
  }

  if (action === "delete") {
    await db.collection("notas").deleteOne({ _id: Number(req.body.IdNota || 0) });
    return redirectWithMessage(res, "notas.php", "success", "Nota removida com sucesso.");
  }

  if (action === "save_pauta") {
    const idDisciplina = Number(req.body.IdDisciplinaPauta || 0);
    const anoLetivo = String(req.body.AnoLetivoPauta || "");
    const epoca = normalizeEpoca(req.body.EpocaPauta || "") || "Normal";
    const ids = Array.isArray(req.body.IdAlunoPauta) ? req.body.IdAlunoPauta : [req.body.IdAlunoPauta];
    const notas = Array.isArray(req.body.NotaFinal) ? req.body.NotaFinal : [req.body.NotaFinal];

    for (let i = 0; i < ids.length; i += 1) {
      const idAluno = Number(ids[i] || 0);
      const notaVal = String(notas[i] || "").trim();
      if (!idAluno || !notaVal) continue;
      const nota = Number(notaVal.replace(",", "."));
      const existing = await db.collection("notas").findOne({ idAluno, idDisciplina, anoLetivo, epoca });
      if (existing) {
        await db.collection("notas").updateOne({ _id: existing._id }, { $set: { nota, dataLancamento: new Date() } });
      } else {
        const _id = await nextNumericId(db.collection("notas"));
        await db.collection("notas").insertOne({ _id, idAluno, idDisciplina, nota, anoLetivo, epoca, dataLancamento: new Date() });
      }
    }

    return redirectWithMessage(res, "notas.php", "success", "Pauta guardada com sucesso.");
  }

  return redirectWithMessage(res, "notas.php", "error", "Acao invalida.");
});

module.exports = router;
