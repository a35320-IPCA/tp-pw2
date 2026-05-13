const { MongoClient } = require("mongodb");

const mongoUri = process.env.MONGODB_URI;
const dbName = process.env.MONGODB_DB || "ipcapw";

let effectiveUri = mongoUri;
if (!effectiveUri) {
  // Avoid embedding credentials in source. Default to local MongoDB for development.
  console.warn("MONGODB_URI não definido — a aplicação irá usar mongodb://localhost:27017 (sem autenticação). Para produção, defina MONGODB_URI.");
  effectiveUri = "mongodb://localhost:27017";
}

let client;
let db;

async function connectToDatabase() {
  if (db) {
    return db;
  }

  // Assumption: traditional long-running web server with moderate concurrency.
  client = new MongoClient(effectiveUri, {
    maxPoolSize: Number(process.env.MONGODB_MAX_POOL_SIZE || 30),
    minPoolSize: Number(process.env.MONGODB_MIN_POOL_SIZE || 5),
    maxIdleTimeMS: Number(process.env.MONGODB_MAX_IDLE_TIME_MS || 300000),
    connectTimeoutMS: Number(process.env.MONGODB_CONNECT_TIMEOUT_MS || 8000),
    socketTimeoutMS: Number(process.env.MONGODB_SOCKET_TIMEOUT_MS || 30000),
    serverSelectionTimeoutMS: Number(process.env.MONGODB_SERVER_SELECTION_TIMEOUT_MS || 5000)
  });

  await client.connect();
  db = client.db(dbName);
  return db;
}

function getDb() {
  if (!db) {
    throw new Error("MongoDB ainda nao esta ligado. Chama connectToDatabase() no arranque.");
  }
  return db;
}

async function closeDatabase() {
  if (client) {
    await client.close();
  }
  client = undefined;
  db = undefined;
}

module.exports = {
  connectToDatabase,
  getDb,
  closeDatabase
};
