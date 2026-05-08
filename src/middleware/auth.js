function requireAuth(req, res, next) {
  if (!req.session || !req.session.authenticated || !req.session.user) {
    return res.status(401).json({ error: "Nao autenticado." });
  }
  return next();
}

function requireRole(...roles) {
  return (req, res, next) => {
    if (!req.session || !req.session.user) {
      return res.status(401).json({ error: "Nao autenticado." });
    }

    const perfil = String(req.session.user.perfil || "").toLowerCase();
    if (!roles.includes(perfil)) {
      return res.status(403).json({ error: "Sem permissao." });
    }
    return next();
  };
}

module.exports = {
  requireAuth,
  requireRole
};
