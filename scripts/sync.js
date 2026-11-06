import "dotenv/config";
import { createStore } from "../server/store.js";
import { syncMonday } from "../server/monday.js";
const store = await createStore();
console.log(await syncMonday(store, { boardIds: process.argv.slice(2) }));
await store.close();
