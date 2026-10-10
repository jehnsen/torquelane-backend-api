create or replace function public.char_now() returns timestamptz language sql stable as $$
  select coalesce(nullif(current_setting('char.now', true), '')::timestamptz, now())
$$;
