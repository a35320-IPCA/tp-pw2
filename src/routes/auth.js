const express = require("express");
const bcrypt = require("bcryptjs");
const { getDb } = require("../config/db");

const router = express.Router();

router.post("/login", async (req, res) => {
  const login = String(req.body.login || "").trim();
  const pwd = String(req.body.pwd || "");

  if (!login || !pwd) {
    return res.status(400).json({ error: "Login e palavra-passe sao obrigatorios." });
  }

  try {
    const db = getDb();
    const user = await db.collection("users").findOne(
      { login: { $regex: `^${login.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}$`, $options: "i" } },
      { projection: { _id: 1, login: 1, pwd: 1, idPerfil: 1 } }
    );

    if (!user) {
      return res.status(401).json({ error: "Login ou palavra-passe invalidos." });
    }

    // Hashes antigas podem usar prefixo 2y, convertido para 2b para comparacao no bcryptjs.
    const normalizedHash = String(user.pwd || "").replace(/^\$2y\$/, "$2b$");
    const valid = await bcrypt.compare(pwd, normalizedHash);
    if (!valid) {
      return res.status(401).json({ error: "Login ou palavra-passe invalidos." });
    }

    const perfilDoc = await db.collection("perfis").findOne({ _id: Number(user.idPerfil) });
    const perfil = perfilDoc ? String(perfilDoc.perfil || "") : "";

    req.session.authenticated = true;
    req.session.user = {
      idUser: Number(user._id),
      login: user.login,
      idPerfil: Number(user.idPerfil),
      perfil
    };

    return res.json({
      ok: true,
      user: req.session.user
    });
  } catch (error) {
    return res.status(500).json({ error: "Erro interno ao autenticar.", detail: error.message });
  }
});

router.post("/logout", (req, res) => {
  req.session.destroy(() => {
    res.json({ ok: true });
  });
});

router.get("/me", (req, res) => {
  if (!req.session || !req.session.authenticated || !req.session.user) {
    return res.status(401).json({ error: "Nao autenticado." });
  }

  return res.json({ user: req.session.user });
});

module.exports = router;
