import "dotenv/config";
import { createStore } from "../server/store.js";
import { syncSheets } from "../server/sheets.js";
const store = await createStore();
console.log(JSON.stringify(await syncSheets(store), null, 1));
await store.close();
