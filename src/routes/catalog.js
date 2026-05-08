const express = require("express");
const { getDb } = require("../config/db");
const { requireAuth, requireRole } = require("../middleware/auth");

const router = express.Router();

router.get("/cursos", requireAuth, async (_req, res) => {
  try {
    const rows = await getDb().collection("cursos").find({}).sort({ curso: 1 }).toArray();
    res.json(rows);
  } catch (error) {
    res.status(500).json({ error: "Falha ao listar cursos.", detail: error.message });
  }
});

router.post("/cursos", requireAuth, requireRole("gestor"), async (req, res) => {
  const curso = String(req.body.curso || "").trim();
  const sigla = String(req.body.sigla || "").trim();
  const id = Number(req.body._id || 0);

  if (!curso || !sigla || id <= 0) {
    return res.status(400).json({ error: "Campos _id, curso e sigla sao obrigatorios." });
  }

  try {
    await getDb().collection("cursos").insertOne({ _id: id, curso, sigla });
    return res.status(201).json({ ok: true });
  } catch (error) {
    return res.status(500).json({ error: "Falha ao criar curso.", detail: error.message });
  }
});

router.put("/cursos/:id", requireAuth, requireRole("gestor"), async (req, res) => {
  const id = Number(req.params.id);
  const curso = String(req.body.curso || "").trim();
  const sigla = String(req.body.sigla || "").trim();

  if (id <= 0 || !curso || !sigla) {
    return res.status(400).json({ error: "Dados invalidos." });
  }

  try {
    const result = await getDb().collection("cursos").updateOne({ _id: id }, { $set: { curso, sigla } });
    if (!result.matchedCount) {
      return res.status(404).json({ error: "Curso nao encontrado." });
    }
    return res.json({ ok: true });
  } catch (error) {
    return res.status(500).json({ error: "Falha ao atualizar curso.", detail: error.message });
  }
});

router.delete("/cursos/:id", requireAuth, requireRole("gestor"), async (req, res) => {
  const id = Number(req.params.id);
  if (id <= 0) {
    return res.status(400).json({ error: "ID invalido." });
  }

  try {
    const result = await getDb().collection("cursos").deleteOne({ _id: id });
    if (!result.deletedCount) {
      return res.status(404).json({ error: "Curso nao encontrado." });
    }
    return res.json({ ok: true });
  } catch (error) {
    return res.status(500).json({ error: "Falha ao apagar curso.", detail: error.message });
  }
});

router.get("/disciplinas", requireAuth, async (_req, res) => {
  try {
    const rows = await getDb().collection("disciplinas").find({}).sort({ disciplina: 1 }).toArray();
    res.json(rows);
  } catch (error) {
    res.status(500).json({ error: "Falha ao listar disciplinas.", detail: error.message });
  }
});

router.get("/plano-estudos", requireAuth, async (req, res) => {
  const idCurso = Number(req.query.idCurso || 0);

  try {
    const match = idCurso > 0 ? { idCurso } : {};
    const rows = await getDb()
      .collection("planoEstudos")
      .aggregate([
        { $match: match },
        {
          $lookup: {
            from: "disciplinas",
            localField: "idDisciplina",
            foreignField: "_id",
            as: "disciplina"
          }
        },
        { $unwind: "$disciplina" },
        {
          $lookup: {
            from: "cursos",
            localField: "idCurso",
            foreignField: "_id",
            as: "curso"
          }
        },
        { $unwind: "$curso" },
        {
          $project: {
            _id: 1,
            idCurso: 1,
            idDisciplina: 1,
            semestre: 1,
            curso: "$curso.curso",
            disciplina: "$disciplina.disciplina"
          }
        },
        { $sort: { curso: 1, semestre: 1, disciplina: 1 } }
      ])
      .toArray();

    return res.json(rows);
  } catch (error) {
    return res.status(500).json({ error: "Falha ao listar plano de estudos.", detail: error.message });
  }
});

module.exports = router;
