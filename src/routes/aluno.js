const express = require("express");
const { getDb } = require("../config/db");
const { requireAuth, requireRole } = require("../middleware/auth");

const router = express.Router();

router.get("/me/ficha", requireAuth, requireRole("aluno"), async (req, res) => {
  try {
    const idUser = Number(req.session.user.idUser);
    const ficha = await getDb().collection("fichaAluno").findOne({ idUser });
    return res.json(ficha || null);
  } catch (error) {
    return res.status(500).json({ error: "Falha ao obter ficha.", detail: error.message });
  }
});

router.get("/me/notas", requireAuth, requireRole("aluno"), async (req, res) => {
  try {
    const idUser = Number(req.session.user.idUser);
    const matricula = await getDb().collection("matriculas").findOne({ idAluno: idUser, status: { $regex: "^aceite$", $options: "i" } });

    if (!matricula) {
      return res.json({ curso: null, notas: [] });
    }

    const notas = await getDb()
      .collection("notas")
      .aggregate([
        { $match: { idAluno: idUser } },
        {
          $lookup: {
            from: "disciplinas",
            localField: "idDisciplina",
            foreignField: "_id",
            as: "disciplina"
          }
        },
        { $unwind: { path: "$disciplina", preserveNullAndEmptyArrays: true } },
        {
          $project: {
            _id: 1,
            idDisciplina: 1,
            nota: 1,
            anoLetivo: 1,
            epoca: 1,
            dataLancamento: 1,
            disciplina: "$disciplina.disciplina"
          }
        },
        { $sort: { dataLancamento: -1, disciplina: 1 } }
      ])
      .toArray();

    const curso = await getDb().collection("cursos").findOne({ _id: Number(matricula.idCurso) });

    return res.json({
      curso: curso ? curso.curso : null,
      notas
    });
  } catch (error) {
    return res.status(500).json({ error: "Falha ao obter notas do aluno.", detail: error.message });
  }
});

module.exports = router;
