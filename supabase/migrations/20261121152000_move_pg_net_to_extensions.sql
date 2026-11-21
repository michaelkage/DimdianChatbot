-- Reinstall pg_net in the recommended extensions schema; its HTTP API remains under net.*.
DROP EXTENSION IF EXISTS pg_net;
CREATE EXTENSION pg_net WITH SCHEMA extensions;
