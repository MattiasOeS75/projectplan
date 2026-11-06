import "dotenv/config";
import { listBoards } from "../server/monday.js";
for (const b of await listBoards()) console.log(`${b.id}\t${b.workspace?.name ?? ""}\t${b.name}`);
