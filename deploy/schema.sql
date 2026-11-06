-- Crosby produktionsplan – dokumentlager som speglar artefaktens db-modell
-- (collections: projects, people, blocks, absence; ett JSON-dokument per id).
CREATE TABLE IF NOT EXISTS docs (
  collection  text        NOT NULL,
  id          text        NOT NULL,
  data        jsonb       NOT NULL,
  updated_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (collection, id)
);
CREATE INDEX IF NOT EXISTS docs_collection_idx ON docs (collection);
CREATE INDEX IF NOT EXISTS docs_updated_idx    ON docs (updated_at);

-- Rå Monday-data (senaste synk), för felsökning och mappning
CREATE TABLE IF NOT EXISTS monday_items (
  board_id    bigint      NOT NULL,
  item_id     bigint      NOT NULL,
  name        text,
  group_id    text,
  columns     jsonb       NOT NULL,
  synced_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (board_id, item_id)
);
