require("dotenv").config();

const app = require("./app");
const { connectToDatabase, closeDatabase } = require("./config/db");

const port = Number(process.env.PORT || 3000);

async function start() {
  await connectToDatabase();

  const server = app.listen(port, () => {
    // eslint-disable-next-line no-console
    console.log(`Servidor Node ativo na porta ${port}`);
  });

  const shutdown = async () => {
    server.close(async () => {
      await closeDatabase();
      process.exit(0);
    });
  };

  process.on("SIGINT", shutdown);
  process.on("SIGTERM", shutdown);
}

start().catch((error) => {
  // eslint-disable-next-line no-console
  console.error("Falha ao arrancar o servidor:", error);
  process.exit(1);
});
