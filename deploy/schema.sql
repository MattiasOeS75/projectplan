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

-- Historik: varje tidigare version av ett dokument sparas (ångra/spårbarhet)
CREATE TABLE IF NOT EXISTS docs_history (
  hid         bigserial   PRIMARY KEY,
  collection  text        NOT NULL,
  id          text        NOT NULL,
  data        jsonb,
  op          text        NOT NULL,            -- UPDATE eller DELETE (värdet före ändringen)
  changed_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS docs_history_doc_idx ON docs_history (collection, id, changed_at DESC);

CREATE OR REPLACE FUNCTION docs_history_fn() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'UPDATE' AND NEW.data IS NOT DISTINCT FROM OLD.data THEN RETURN NEW; END IF;
  INSERT INTO docs_history (collection, id, data, op) VALUES (OLD.collection, OLD.id, OLD.data, TG_OP);
  RETURN COALESCE(NEW, OLD);
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS docs_history_trg ON docs;
CREATE TRIGGER docs_history_trg BEFORE UPDATE OR DELETE ON docs FOR EACH ROW EXECUTE FUNCTION docs_history_fn();
