-- Mirrors the two Supabase roles PostgREST runs browser requests as, so the
-- revoke_supabase_api_roles migration is exercised locally and in CI instead
-- of being a no-op. Cluster-wide, so the per-process databases created by
-- parallel tests see them too. Also run by .github/workflows/ci.yml.
do $$
begin
    if not exists (select 1 from pg_roles where rolname = 'anon') then
        create role anon nologin;
    end if;
    if not exists (select 1 from pg_roles where rolname = 'authenticated') then
        create role authenticated nologin;
    end if;
end
$$;
