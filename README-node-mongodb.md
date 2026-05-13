# Migracao para Node.js + MongoDB

Este projeto usa um backend Node.js (Express) com MongoDB.

## 1) Instalar dependencias

```bash
npm install
```

## 2) Configurar ambiente

1. Copia `.env.example` para `.env`
2. Ajusta:
- `MONGODB_URI`
- `MONGODB_DB`
- `SESSION_SECRET`

## 3) Importar base de dados no MongoDB Atlas

### Opcao A (recomendada): seed Node

1. Define `MONGODB_URI` com a connection string do cluster Atlas.
2. No terminal, executa:

```bash
node scripts/mongodb-seed.js
```

O projeto usa Atlas por defeito quando `MONGODB_URI` nao estiver definido.

### Opcao B: MongoDB Compass

1. Abre a connection string do cluster Atlas.
2. Seleciona a base `ipcapw`.
3. Usa o seed Node acima para repor os dados no cluster.

## 4) Arrancar servidor Node

```bash
npm run dev
```

## Endpoints iniciais

- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/auth/me`
- `GET /api/cursos`
- `POST /api/cursos` (gestor)
- `PUT /api/cursos/:id` (gestor)
- `DELETE /api/cursos/:id` (gestor)
- `GET /api/disciplinas`
- `GET /api/plano-estudos`
- `GET /api/aluno/me/ficha` (aluno)
- `GET /api/aluno/me/notas` (aluno)

## Nota

A interface principal ja esta em Node com views EJS e rotas web.
