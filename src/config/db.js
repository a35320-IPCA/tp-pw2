const { MongoClient } = require("mongodb");

const mongoUri = process.env.MONGODB_URI || "mongodb://a35320_db_user:yuZbp4foOao06eJ7@ac-7esch4f-shard-00-00.iiayayl.mongodb.net:27017,ac-7esch4f-shard-00-01.iiayayl.mongodb.net:27017,ac-7esch4f-shard-00-02.iiayayl.mongodb.net:27017/?ssl=true&replicaSet=atlas-j43qb3-shard-0&authSource=admin&appName=Cluster0";
const dbName = process.env.MONGODB_DB || "ipcapw";

let client;
let db;

async function connectToDatabase() {
  if (db) {
    return db;
  }

  // Assumption: traditional long-running web server with moderate concurrency.
  client = new MongoClient(mongoUri, {
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
