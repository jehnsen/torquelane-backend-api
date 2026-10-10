-- Stand-ins for what Supabase provides, so the source's SQL loads unchanged.
create schema if not exists auth;
create or replace function auth.uid() returns uuid language sql stable as $$ select nullif(current_setting('char.uid', true), '')::uuid $$;
create or replace function auth.jwt() returns jsonb language sql stable as $$ select jsonb_build_object('email', coalesce(current_setting('char.email', true), '')) $$;
create or replace function auth.email() returns text language sql stable as $$ select coalesce(current_setting('char.email', true), '') $$;
do $$ begin if not exists (select 1 from pg_publication where pubname = 'supabase_realtime') then create publication supabase_realtime; end if; end $$;
