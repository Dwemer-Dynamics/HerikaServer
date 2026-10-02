-- Exact diary/book authorship (docs/actor-identity.md). Additive and idempotent; reruns after restores.
-- author_key is the immutable physical author captured at generation. NULL rows are legacy/unassigned:
-- they stay visible to admins but are never assigned to a current namesake.
ALTER TABLE public.diarylog ADD COLUMN IF NOT EXISTS author_key text;
CREATE INDEX IF NOT EXISTS idx_diarylog_author_key_gamets
    ON public.diarylog (author_key, gamets DESC, rowid DESC) WHERE author_key IS NOT NULL;

-- Generated/uploaded book identity. book_key = 'diary:' || author_key for physical diaries;
-- book_instance and content_version (16 hex FNV-1a 64) come from the native cosave copy.
ALTER TABLE public.books ADD COLUMN IF NOT EXISTS book_key text;
ALTER TABLE public.books ADD COLUMN IF NOT EXISTS author_key text;
ALTER TABLE public.books ADD COLUMN IF NOT EXISTS recipient_key text;
ALTER TABLE public.books ADD COLUMN IF NOT EXISTS reader_key text;
ALTER TABLE public.books ADD COLUMN IF NOT EXISTS book_instance text;
ALTER TABLE public.books ADD COLUMN IF NOT EXISTS content_version text;
CREATE INDEX IF NOT EXISTS idx_books_book_key ON public.books (book_key, rowid DESC) WHERE book_key IS NOT NULL;

-- Physical diary tracking moves from npc_name to author_key uniqueness. Legacy rows keep npc_name
-- uniqueness among unassigned rows and are never rewritten.
ALTER TABLE public.physical_npc_diaries ADD COLUMN IF NOT EXISTS author_key text;
DO $$
DECLARE pk text;
BEGIN
    SELECT c.conname INTO pk FROM pg_constraint c
    WHERE c.conrelid = 'public.physical_npc_diaries'::regclass AND c.contype = 'p'
      AND c.conkey = ARRAY[(SELECT attnum FROM pg_attribute
          WHERE attrelid = 'public.physical_npc_diaries'::regclass AND attname = 'npc_name')]::int2[];
    IF pk IS NOT NULL THEN
        EXECUTE format('ALTER TABLE public.physical_npc_diaries DROP CONSTRAINT %I', pk);
    END IF;
END $$;
CREATE UNIQUE INDEX IF NOT EXISTS physical_npc_diaries_author_key_uidx
    ON public.physical_npc_diaries (author_key) WHERE author_key IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS physical_npc_diaries_legacy_name_uidx
    ON public.physical_npc_diaries (npc_name) WHERE author_key IS NULL;
