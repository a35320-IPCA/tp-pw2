const express = require("express");
const session = require("express-session");
const path = require("path");

const authRoutes = require("./routes/auth");
const catalogRoutes = require("./routes/catalog");
const alunoRoutes = require("./routes/aluno");
const webRoutes = require("./routes/web");

const app = express();

app.set("view engine", "ejs");
app.set("views", path.join(__dirname, "..", "views"));

app.use(express.json({ limit: "5mb" }));
app.use(express.urlencoded({ extended: true, limit: "5mb" }));

app.use(
  session({
    name: "gp.sid",
    secret: process.env.SESSION_SECRET || "dev-secret-change-me",
    resave: false,
    saveUninitialized: false,
    cookie: {
      httpOnly: true,
      sameSite: "lax",
      secure: false,
      maxAge: 8 * 60 * 60 * 1000
    }
  })
);

app.use((req, res, next) => {
  if (req.path.toLowerCase().endsWith(".php")) {
    const targetPath = req.path.slice(0, -4) || "/";
    const queryIndex = req.originalUrl.indexOf("?");
    const queryString = queryIndex >= 0 ? req.originalUrl.slice(queryIndex) : "";
    return res.redirect(301, `${targetPath}${queryString}`);
  }

  next();
});

app.use("/css", express.static(path.join(__dirname, "..", "css")));
app.use("/js", express.static(path.join(__dirname, "..", "js")));
app.use("/images", express.static(path.join(__dirname, "..", "images")));

app.get("/", (_req, res) => {
  res.redirect("/dashboard");
});

app.use("/", webRoutes);

app.use("/api/auth", authRoutes);
app.use("/api", catalogRoutes);
app.use("/api/aluno", alunoRoutes);

app.use((err, _req, res, _next) => {
  res.status(500).json({ error: "Erro interno.", detail: err.message });
});

module.exports = app;
