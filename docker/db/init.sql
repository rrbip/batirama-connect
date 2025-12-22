-- ===========================================
-- AI-Manager CMS - Initialisation PostgreSQL
-- ===========================================

-- Extensions utiles
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";  -- Pour la recherche texte

-- Commentaire
COMMENT ON DATABASE ai_manager IS 'AI-Manager CMS - Base principale';
